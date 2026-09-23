<?php
// src/Knowledge/Terms.php
// Trade terminology: the names customers actually use. "Roman nose" and
// "blast plate" mean a brick cover; "amp" means an amplifier (active), which
// is NOT the same as a passive splitter. Matches are used to widen the
// search, to explain the difference to Max in the prompt, and - when nothing
// is found - to route the chat to the right department.

declare(strict_types=1);

namespace Knowledge;

class Terms
{
    public static function all(bool $activeOnly = true): array
    {
        try {
            $sql = 'SELECT * FROM term_aliases' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY term';
            return db()->query($sql)->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    public static function aliasList(array $row): array
    {
        $out = [];
        foreach (explode(',', (string)$row['aliases']) as $a) {
            $a = trim(mb_strtolower($a));
            if ($a !== '') $out[] = $a;
        }
        return $out;
    }

    // Terms mentioned in the customer's message (alias or the term itself).
    public static function match(string $message): array
    {
        $m = ' ' . mb_strtolower(preg_replace('/[^\p{L}\p{N}\s-]+/u', ' ', $message)) . ' ';
        $hits = [];
        foreach (self::all() as $row) {
            foreach (array_merge(self::aliasList($row), [mb_strtolower((string)$row['term'])]) as $alias) {
                if ($alias === '') continue;
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($alias, '/') . '(?![\p{L}\p{N}])/u', $m)) {
                    $hits[$row['id']] = $row;
                    break;
                }
            }
        }
        return array_values($hits);
    }

    // The search query with the proper trade words added.
    public static function expand(string $query, array $matches): string
    {
        $extra = [];
        foreach ($matches as $row) {
            $extra[] = (string)$row['term'];
            foreach (explode(',', (string)($row['search_terms'] ?? '')) as $t) {
                $t = trim($t);
                if ($t !== '') $extra[] = $t;
            }
        }
        $extra = array_slice(array_values(array_unique(array_filter($extra))), 0, 8);
        return $extra ? trim($query . ' ' . implode(' ', $extra)) : $query;
    }

    public static function promptBlock(array $matches): string
    {
        if (!$matches) return '';
        $lines = [];
        foreach ($matches as $r) {
            $line = '- "' . implode('", "', array_slice(self::aliasList($r), 0, 4)) . '" means ' . $r['term'];
            if (!empty($r['note'])) $line .= '. ' . rtrim((string)$r['note'], '.') . '.';
            if (!empty($r['product_codes'])) $line .= ' Example codes: ' . $r['product_codes'] . '.';
            $lines[] = $line;
        }
        return "TRADE TERMS the customer may be using (our own glossary - follow it over your own assumptions):\n" . implode("\n", $lines);
    }

    // Department to pass the chat to when we still can't answer.
    public static function department(array $matches): ?string
    {
        foreach ($matches as $r) {
            $d = trim((string)($r['department'] ?? ''));
            if ($d !== '' && isset(\Chat\Handoff::DEPARTMENTS[$d])) return $d;
        }
        return null;
    }

    public static function save(array $d): array
    {
        $term = trim((string)($d['term'] ?? ''));
        $aliases = trim((string)($d['aliases'] ?? ''));
        if ($term === '' || $aliases === '') return ['ok' => false, 'error' => 'A term and at least one alias are required.'];
        $args = [$term, $aliases, trim((string)($d['search_terms'] ?? '')), trim((string)($d['note'] ?? '')),
                 trim((string)($d['product_codes'] ?? '')), trim((string)($d['department'] ?? '')), empty($d['active']) ? 0 : 1, time()];
        if (!empty($d['id'])) {
            $args[] = (int)$d['id'];
            db()->prepare('UPDATE term_aliases SET term=?, aliases=?, search_terms=?, note=?, product_codes=?, department=?, active=?, updated_at=? WHERE id=?')->execute($args);
            return ['ok' => true, 'id' => (int)$d['id']];
        }
        db()->prepare('INSERT INTO term_aliases (term, aliases, search_terms, note, product_codes, department, active, updated_at) VALUES (?,?,?,?,?,?,?,?)')->execute($args);
        return ['ok' => true, 'id' => (int)db()->lastInsertId()];
    }

    public static function delete(int $id): void
    {
        db()->prepare('DELETE FROM term_aliases WHERE id = ?')->execute([$id]);
    }
}
