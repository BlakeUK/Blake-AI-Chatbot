<?php
// src/Knowledge/StandardsChunker.php
// Splits the text of an ETSI/DVB standard (pdftotext -layout output) into
// one chunk per clause, so every chunk carries its clause number and title
// for citations ("EN 302 755 clause 8.3.1"). Drops what is useless for
// answering questions: page headers/footers, the contents list, IPR notice,
// foreword, modal-verb boilerplate, the references clause, bibliography and
// history. Long clauses are split on paragraph breaks; column layouts
// (tables) are kept, with wide gaps shown as " | ".

declare(strict_types=1);

namespace Knowledge;

class StandardsChunker
{
    public const MAX_CHARS = 2200;
    public const MIN_CHARS = 160;
    private const SKIP_UNNUMBERED = ['intellectual property rights', 'foreword', 'modal verbs terminology', 'history', 'contents', 'executive summary'];

    // $doc: ['code' => 'ETSI EN 302 755', 'short' => 'DVB-T2']
    // Returns [['clause' => '8.3.1', 'title' => '...', 'text' => '...'], ...]
    public static function chunk(string $raw, array $doc): array
    {
        $lines = preg_split('/\R/', str_replace("\f", "\n", $raw));
        $clauses = [];
        $cur = null;
        $started = false;
        $skip = false;
        $pendingAnnex = null;
        $seenScope = false;   // body starts at clause 1 (Scope); anything before is contents/boilerplate

        $flush = function () use (&$cur, &$clauses, &$skip) {
            if ($cur !== null && !$skip) $clauses[] = $cur;
            $cur = null;
        };

        foreach ($lines as $line) {
            $t = rtrim($line);
            $trim = trim($t);
            // Page header ("  5   ETSI EN 302 755 V1.4.1 (2015-07)") / footer ("ETSI").
            if ($trim === 'ETSI' || preg_match('/^\d+\s+ETSI\s+(EN|TS|TR|ES|EG)\s+[\d\- ]+V\d/', $trim)) continue;
            // Contents list lines have dot leaders.
            if (preg_match('/\.{6,}\s*\d*\s*$/', $trim)) continue;

            // "Annex A (normative):" with its title on the same or next line.
            if ($seenScope && preg_match('/^Annex\s+([A-Z])\s*\((normative|informative)\):\s*(.*)$/', $trim, $m)) {
                $flush();
                $pendingAnnex = ['clause' => 'Annex ' . $m[1], 'title' => trim($m[3])];
                if ($pendingAnnex['title'] !== '') {
                    $skip = (bool)preg_match('/bibliograph|change history|history/i', $pendingAnnex['title']);
                    $cur = $pendingAnnex + ['text' => '']; $pendingAnnex = null;
                }
                $started = true;
                continue;
            }
            if ($pendingAnnex !== null && $trim !== '') {
                $pendingAnnex['title'] = $trim;
                $skip = (bool)preg_match('/bibliograph|change history|history/i', $trim);
                $cur = $pendingAnnex + ['text' => '']; $pendingAnnex = null;
                continue;
            }

            // Numbered clause heading: "8.3.1   Duration of the T2-Frame", "A.2  Title".
            if (preg_match('/^((?:[A-Z]\.)?\d{1,2}(?:\.\d{1,2}){0,6})\s{2,}([A-Z0-9][^\n]{1,110})$/', $trim, $m)
                && !preg_match('/\s{3,}\S/', $m[2]) && !preg_match('/[.;,]$/', $m[2])) {
                $num = $m[1];
                if (!$seenScope && $num !== '1') continue;
                if ($num === '1') $seenScope = true;
                if (!$started && !$seenScope) continue;
                $started = true;
                $flush();
                $skip = ($num === '2' || str_starts_with($num, '2.'));    // references
                $cur = ['clause' => $num, 'title' => trim($m[2]), 'text' => ''];
                continue;
            }
            // Unnumbered boilerplate headings.
            if (in_array(strtolower($trim), self::SKIP_UNNUMBERED, true)) {
                $flush();
                $skip = true;
                $cur = ['clause' => '', 'title' => $trim, 'text' => ''];
                if (strtolower($trim) === 'history') $started = false;   // end of document
                continue;
            }
            if ($cur === null || !$started) continue;
            $cur['text'] .= $t . "\n";
        }
        $flush();

        // Tidy, merge stubs into the next clause, split long ones.
        $out = [];
        $carry = '';
        foreach ($clauses as $c) {
            $text = self::tidy($c['text']);
            if ($carry !== '') { $text = trim($carry . "\n\n" . $text); $carry = ''; }
            if (mb_strlen($text) < self::MIN_CHARS) {
                $carry = trim(($c['clause'] ? "{$c['clause']} " : '') . $c['title'] . "\n" . $text);
                continue;
            }
            foreach (self::split($text) as $i => $part) {
                $out[] = [
                    'clause' => $c['clause'],
                    'title'  => $c['title'],
                    'text'   => self::label($doc, $c, $i) . "\n" . $part,
                ];
            }
        }
        if ($carry !== '' && $out) {
            $out[count($out) - 1]['text'] .= "\n\n" . $carry;
        }
        return $out;
    }

    private static function label(array $doc, array $c, int $part): string
    {
        $where = $c['clause'] !== '' ? (str_starts_with($c['clause'], 'Annex') ? $c['clause'] : 'clause ' . $c['clause']) : '';
        return "[{$doc['code']} ({$doc['short']})" . ($where ? ", {$where}" : '') . " {$c['title']}" . ($part ? ' (continued)' : '') . ']';
    }

    public static function tidy(string $text): string
    {
        $out = [];
        foreach (preg_split('/\R/', $text) as $l) {
            $l = rtrim($l);
            $lead = strlen($l) - strlen(ltrim($l));
            $l = trim($l);
            if ($l === '') { $out[] = ''; continue; }
            // Column gaps in tables -> " | "; keep ordinary text readable.
            $l = preg_replace('/\s{3,}/', ' | ', $l);
            $out[] = ($lead > 12 && str_contains($l, ' | ') ? '' : '') . $l;
        }
        $t = implode("\n", $out);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t);
    }

    private static function split(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHARS) return [$text];
        $paras = preg_split("/\n{2,}/", $text);
        $parts = []; $buf = '';
        foreach ($paras as $p) {
            if ($buf !== '' && mb_strlen($buf) + mb_strlen($p) + 2 > self::MAX_CHARS) {
                $parts[] = $buf;
                // one paragraph of overlap keeps context across the split
                $last = substr($buf, (int)strrpos($buf, "\n\n"));
                $buf = mb_strlen($last) < 600 ? trim($last) . "\n\n" . $p : $p;
            } else {
                $buf = $buf === '' ? $p : $buf . "\n\n" . $p;
            }
            while (mb_strlen($buf) > self::MAX_CHARS * 1.5) {   // one huge paragraph/table
                $parts[] = mb_substr($buf, 0, self::MAX_CHARS);
                $buf = mb_substr($buf, self::MAX_CHARS - 200);
            }
        }
        if (trim($buf) !== '') $parts[] = $buf;
        return $parts;
    }
}
