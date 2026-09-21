<?php
// src/Knowledge/Downloads.php
// Uploaded knowledge files an admin has marked "offer as download" (e.g. the
// trade account application form). When a customer's question matches the
// file's trigger keywords - or the answer is drawn from that file - Max
// offers it, and the widget shows a download card. Files are served by
// public/api/chat/file.php using an unguessable token, and only if marked.

declare(strict_types=1);

namespace Knowledge;

class Downloads
{
    public static function baseUrl(): string
    {
        return rtrim((string)(CFG['public_base_url'] ?? 'https://chat.blakegroup.uk'), '/');
    }

    public static function url(array $f): string
    {
        return self::baseUrl() . '/api/chat/file.php?t=' . rawurlencode((string)$f['download_token']);
    }

    public static function title(array $f): string
    {
        $t = trim((string)($f['download_title'] ?? ''));
        if ($t !== '') return $t;
        $t = preg_replace('/\.[a-z0-9]{2,4}$/i', '', (string)$f['filename']);
        return trim(preg_replace('/[_\s]+/', ' ', $t));
    }

    public static function all(): array
    {
        try {
            return db()->query("SELECT id, filename, mime_type, stored_path, download_title, download_keywords, download_token
                                FROM knowledge_files WHERE public_download = 1 AND status = 'indexed' AND download_token IS NOT NULL")->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    // Downloads relevant to this question: keyword match, or the answer's
    // knowledge hits came from the file.
    public static function forQuestion(string $message, array $knowledgeHits = []): array
    {
        $files = self::all();
        if (!$files) return [];
        $fromHits = [];
        foreach ($knowledgeHits as $h) {
            if (($h['source_type'] ?? '') === 'file') $fromHits[(int)$h['source_id']] = true;
        }
        $m = ' ' . mb_strtolower($message) . ' ';
        $out = [];
        foreach ($files as $f) {
            $hit = isset($fromHits[(int)$f['id']]);
            foreach (array_filter(array_map('trim', explode(',', mb_strtolower((string)$f['download_keywords'])))) as $kw) {
                if ($kw !== '' && str_contains($m, $kw)) { $hit = true; break; }
            }
            if ($hit) $out[] = ['id' => (int)$f['id'], 'title' => self::title($f), 'url' => self::url($f),
                                'type' => strtoupper(pathinfo((string)$f['filename'], PATHINFO_EXTENSION)) ?: 'FILE'];
        }
        return array_slice($out, 0, 3);
    }

    // Mark / unmark a file as a customer download.
    public static function set(int $fileId, bool $public, ?string $title = null, ?string $keywords = null): array
    {
        $s = db()->prepare('SELECT id, download_token FROM knowledge_files WHERE id = ?');
        $s->execute([$fileId]);
        $row = $s->fetch();
        if (!$row) return ['ok' => false, 'error' => 'File not found'];
        $token = $row['download_token'] ?: bin2hex(random_bytes(16));
        db()->prepare('UPDATE knowledge_files SET public_download = ?, download_title = ?, download_keywords = ?, download_token = ? WHERE id = ?')
            ->execute([$public ? 1 : 0, $title !== null ? trim($title) : null, $keywords !== null ? trim($keywords) : null, $token, $fileId]);
        return ['ok' => true, 'token' => $token];
    }
}
