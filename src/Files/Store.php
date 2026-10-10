<?php
// src/Files/Store.php: each staff member's folders and files. The bytes live outside the website folder under random names and
// are only ever handed out through PHP (as downloads). Folders nest; names are kept unique within a folder.

declare(strict_types=1);

namespace Files;

class Store
{
    public const MAX_FILE   = 250 * 1024 * 1024;     // one file
    public const MAX_ZIP    = 1024 * 1024 * 1024;    // what one zip may hold or expand to
    public const CHUNK_MAX  = 2 * 1024 * 1024;       // one piece of an upload
    public const DEFAULT_QUOTA_MB = 2000;            // all stored files together (setting fs_quota_mb)

    // ---- where things live -------------------------------------------------------------------------------------------

    public static function root(): string
    {
        $r = (string)(CFG['files_path'] ?? (dirname((string)CFG['db_path']) . '/files'));
        if (!is_dir($r)) @mkdir($r, 0750, true);
        if (!is_dir($r . '/tmp')) @mkdir($r . '/tmp', 0750, true);
        return $r;
    }

    public static function blobPath(string $blob): string
    {
        $d = self::root() . '/' . substr($blob, 0, 2) . '/' . substr($blob, 2, 2);
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d . '/' . $blob;
    }

    public static function newBlob(): string { return bin2hex(random_bytes(16)); }

    public static function quotaBytes(): int
    {
        $q = db()->prepare("SELECT value FROM settings WHERE key = 'fs_quota_mb'");
        $q->execute();
        $mb = (int)$q->fetchColumn();
        return ($mb > 0 ? $mb : self::DEFAULT_QUOTA_MB) * 1024 * 1024;
    }

    public static function totalUsed(): int { return (int)db()->query("SELECT COALESCE(SUM(size),0) FROM fs_nodes WHERE kind = 'file'")->fetchColumn(); }

    public static function usedBy(int $owner): int
    {
        $q = db()->prepare("SELECT COALESCE(SUM(size),0) FROM fs_nodes WHERE kind = 'file' AND owner_id = ?");
        $q->execute([$owner]);
        return (int)$q->fetchColumn();
    }

    // ---- names -------------------------------------------------------------------------------------------------------

    public static function cleanName(string $name): string
    {
        $n = preg_replace('/[\x00-\x1f\x7f]/u', '', $name) ?? '';
        $n = str_replace(['/', '\\'], '-', $n);
        $n = preg_replace('/[<>:"|?*]/u', '_', $n) ?? $n;
        $n = trim($n, " .\t");
        if (mb_strlen($n) > 180) {
            $ext = pathinfo($n, PATHINFO_EXTENSION);
            $n = mb_substr($n, 0, 170) . ($ext !== '' && mb_strlen($ext) <= 8 ? '.' . $ext : '');
        }
        return ($n === '' || $n === '.' || $n === '..') ? 'Untitled' : $n;
    }

    public static function uniqueName(int $owner, ?int $parent, string $name, bool $isFolder, ?int $exclude = null): string
    {
        $name = self::cleanName($name);
        $q = db()->prepare('SELECT LOWER(name) FROM fs_nodes WHERE owner_id = ? AND parent_id IS ? AND id IS NOT ?');
        $q->execute([$owner, $parent, $exclude]);
        $taken = array_flip($q->fetchAll(\PDO::FETCH_COLUMN));
        if (!isset($taken[mb_strtolower($name)])) return $name;
        $ext  = $isFolder ? '' : pathinfo($name, PATHINFO_EXTENSION);
        $base = $ext !== '' ? mb_substr($name, 0, -(mb_strlen($ext) + 1)) : $name;
        for ($i = 2; $i < 1000; $i++) {
            $try = "{$base} ({$i})" . ($ext !== '' ? ".{$ext}" : '');
            if (!isset($taken[mb_strtolower($try)])) return $try;
        }
        return $base . ' (' . bin2hex(random_bytes(3)) . ')' . ($ext !== '' ? ".{$ext}" : '');
    }

    // ---- reading -----------------------------------------------------------------------------------------------------

    public static function get(int $id): ?array
    {
        $q = db()->prepare('SELECT * FROM fs_nodes WHERE id = ?');
        $q->execute([$id]);
        return $q->fetch() ?: null;
    }

    public static function mine(int $id, int $owner): ?array
    {
        $n = self::get($id);
        return ($n && (int)$n['owner_id'] === $owner) ? $n : null;
    }

    /** @return list<array<string,mixed>> folders first, then files, each by name */
    public static function children(int $owner, ?int $parent): array
    {
        $q = db()->prepare("SELECT * FROM fs_nodes WHERE owner_id = ? AND parent_id IS ? ORDER BY kind = 'file', LOWER(name)");
        $q->execute([$owner, $parent]);
        return $q->fetchAll();
    }

    /** @return list<array<string,mixed>> the contents of any folder, whoever owns it (callers must have checked access) */
    public static function contents(int $folderId): array
    {
        $q = db()->prepare("SELECT * FROM fs_nodes WHERE parent_id = ? ORDER BY kind = 'file', LOWER(name)");
        $q->execute([$folderId]);
        return $q->fetchAll();
    }

    /** @return list<array{id:int,name:string}> from the top down to and including $id */
    public static function path(?int $id): array
    {
        $out = [];
        for ($i = 0; $id !== null && $i < 60; $i++) {
            $n = self::get($id);
            if (!$n) break;
            array_unshift($out, ['id' => (int)$n['id'], 'name' => (string)$n['name']]);
            $id = $n['parent_id'] !== null ? (int)$n['parent_id'] : null;
        }
        return $out;
    }

    /** Is $id the same as, or somewhere below, $ancestor? */
    public static function isInside(int $id, int $ancestor): bool
    {
        for ($i = 0; $i < 60 && $id > 0; $i++) {
            if ($id === $ancestor) return true;
            $n = self::get($id);
            if (!$n || $n['parent_id'] === null) return false;
            $id = (int)$n['parent_id'];
        }
        return false;
    }

    // ---- changing ----------------------------------------------------------------------------------------------------

    private static function insert(int $owner, ?int $parent, string $kind, string $name, int $size = 0, ?string $mime = null, ?string $blob = null, ?string $sha = null): int
    {
        $now = time();
        db()->prepare('INSERT INTO fs_nodes (owner_id, parent_id, kind, name, size, mime, blob, sha256, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([$owner, $parent, $kind, $name, $size, $mime, $blob, $sha, $now, $now]);
        return (int)db()->lastInsertId();
    }

    private static function checkFolder(int $owner, ?int $parent): void
    {
        if ($parent !== null && (($p = self::mine($parent, $owner)) === null || $p['kind'] !== 'folder')) throw new \InvalidArgumentException('That folder was not found.');
    }

    public static function mkdir(int $owner, ?int $parent, string $name, bool $reuse = false): int
    {
        self::checkFolder($owner, $parent);
        if ($reuse) {
            $q = db()->prepare("SELECT id FROM fs_nodes WHERE owner_id = ? AND parent_id IS ? AND kind = 'folder' AND LOWER(name) = LOWER(?)");
            $q->execute([$owner, $parent, self::cleanName($name)]);
            if (($id = $q->fetchColumn()) !== false) return (int)$id;
        }
        return self::insert($owner, $parent, 'folder', self::uniqueName($owner, $parent, $name, true));
    }

    public static function rename(int $owner, int $id, string $name): string
    {
        $n = self::mine($id, $owner) ?? throw new \InvalidArgumentException('That item was not found.');
        $new = self::uniqueName($owner, $n['parent_id'] !== null ? (int)$n['parent_id'] : null, $name, $n['kind'] === 'folder', $id);
        db()->prepare('UPDATE fs_nodes SET name = ?, updated_at = ? WHERE id = ?')->execute([$new, time(), $id]);
        return $new;
    }

    /** @param list<int> $ids */
    public static function move(int $owner, array $ids, ?int $parent): void
    {
        self::checkFolder($owner, $parent);
        foreach ($ids as $id) {
            $n = self::mine((int)$id, $owner) ?? throw new \InvalidArgumentException('An item was not found.');
            if ($parent !== null && self::isInside($parent, (int)$id)) throw new \InvalidArgumentException('A folder cannot be moved into itself.');
            $new = self::uniqueName($owner, $parent, $n['name'], $n['kind'] === 'folder', (int)$id);
            db()->prepare('UPDATE fs_nodes SET parent_id = ?, name = ?, updated_at = ? WHERE id = ?')->execute([$parent, $new, time(), (int)$id]);
        }
    }

    /** @param list<int> $ids Removes the items, everything inside folders, the stored bytes, and any share entries for them. Returns how many items went. */
    public static function delete(int $owner, array $ids): int
    {
        $all = [];
        $walk = function (int $id, int $depth = 0) use (&$walk, &$all): void {
            $all[] = $id;
            if ($depth > 40) return;
            $q = db()->prepare('SELECT id FROM fs_nodes WHERE parent_id = ?');
            $q->execute([$id]);
            foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $c) $walk((int)$c, $depth + 1);
        };
        foreach ($ids as $id) if (self::mine((int)$id, $owner)) $walk((int)$id);
        foreach (array_unique($all) as $id) {
            $n = self::get($id);
            if ($n && $n['blob']) @unlink(self::blobPath($n['blob']));
            db()->prepare('DELETE FROM fs_share_items WHERE node_id = ?')->execute([$id]);
            db()->prepare('DELETE FROM fs_nodes WHERE id = ?')->execute([$id]);
        }
        return count(array_unique($all));
    }

    // ---- uploads (in pieces) -----------------------------------------------------------------------------------------

    public static function beginUpload(int $owner, ?int $parent, string $name, int $size): string
    {
        self::checkFolder($owner, $parent);
        if ($size < 0 || $size > self::MAX_FILE) throw new \InvalidArgumentException('That file is too big. The limit is ' . (int)(self::MAX_FILE / 1048576) . ' MB for one file.');
        if (self::totalUsed() + $size > self::quotaBytes()) throw new \InvalidArgumentException('There is not enough storage space left for that file.');
        self::purgeStaleUploads();
        $id = bin2hex(random_bytes(12));
        db()->prepare('INSERT INTO fs_uploads (id, owner_id, parent_id, name, size, received, created_at) VALUES (?,?,?,?,?,0,?)')->execute([$id, $owner, $parent, self::cleanName($name), $size, time()]);
        touch(self::root() . "/tmp/{$id}.part");
        return $id;
    }

    /** Adds the next piece. $offset must be exactly what has been received so far; if not, returns the real position so the sender can carry on from there. */
    public static function chunk(string $id, int $owner, int $offset, string $data): int
    {
        $u = self::upload($id, $owner);
        $len = strlen($data);
        if ($offset !== (int)$u['received']) throw new UploadOutOfStep((int)$u['received']);
        if ($len < 1 || $len > self::CHUNK_MAX) throw new \InvalidArgumentException('That piece of the upload is the wrong size.');
        if ($offset + $len > (int)$u['size']) throw new \InvalidArgumentException('More data arrived than the file said it had.');
        $fh = fopen(self::root() . "/tmp/{$id}.part", 'ab');
        fwrite($fh, $data); fclose($fh);
        db()->prepare('UPDATE fs_uploads SET received = ? WHERE id = ?')->execute([$offset + $len, $id]);
        return $offset + $len;
    }

    public static function finishUpload(string $id, int $owner): array
    {
        $u = self::upload($id, $owner);
        $part = self::root() . "/tmp/{$id}.part";
        if ((int)$u['received'] !== (int)$u['size'] || (int)@filesize($part) !== (int)$u['size']) throw new \InvalidArgumentException('The upload is not complete yet.');
        $blob = self::newBlob();
        $sha  = hash_file('sha256', $part);
        rename($part, self::blobPath($blob));
        $parent = $u['parent_id'] !== null ? (int)$u['parent_id'] : null;
        $nid = self::insert($owner, $parent, 'file', self::uniqueName($owner, $parent, $u['name'], false), (int)$u['size'], self::mimeFor($u['name']), $blob, $sha);
        db()->prepare('DELETE FROM fs_uploads WHERE id = ?')->execute([$id]);
        return self::get($nid);
    }

    private static function upload(string $id, int $owner): array
    {
        $q = db()->prepare('SELECT * FROM fs_uploads WHERE id = ? AND owner_id = ?');
        $q->execute([$id, $owner]);
        return $q->fetch() ?: throw new \InvalidArgumentException('That upload was not found. Please start it again.');
    }

    public static function purgeStaleUploads(): void
    {
        $q = db()->prepare('SELECT id FROM fs_uploads WHERE created_at < ?');
        $q->execute([time() - 86400]);
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $id) {
            @unlink(self::root() . "/tmp/{$id}.part");
            db()->prepare('DELETE FROM fs_uploads WHERE id = ?')->execute([$id]);
        }
    }

    public static function mimeFor(string $name): string
    {
        static $m = ['pdf' => 'application/pdf', 'zip' => 'application/zip', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'txt' => 'text/plain', 'csv' => 'text/csv', 'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation', 'mp4' => 'video/mp4', 'mp3' => 'audio/mpeg'];
        return $m[strtolower(pathinfo($name, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    // ---- zip and unzip -----------------------------------------------------------------------------------------------

    /** What goes in a zip for these items: folders (empty ones too) and files, with their paths. @param list<array<string,mixed>> $nodes @return list<array<string,mixed>> */
    public static function collect(array $nodes): array
    {
        $out = []; $total = 0; $names = [];
        $walk = function (array $n, string $prefix, int $depth) use (&$walk, &$out, &$total): void {
            if ($depth > 30) throw new \InvalidArgumentException('Those folders are nested too deeply.');
            if ($n['kind'] === 'folder') {
                $out[] = ['name' => $prefix . $n['name'], 'path' => null, 'mtime' => (int)$n['updated_at']];
                foreach (self::contents((int)$n['id']) as $c) $walk($c, $prefix . $n['name'] . '/', $depth + 1);
            } else {
                $total += (int)$n['size'];
                $out[] = ['name' => $prefix . $n['name'], 'path' => self::blobPath((string)$n['blob']), 'mtime' => (int)$n['updated_at'], 'size' => (int)$n['size']];
            }
            if (count($out) > Zip::MAX_ENTRIES) throw new \InvalidArgumentException('That is too many files for one zip.');
            if ($total > self::MAX_ZIP) throw new \InvalidArgumentException('That is too much data for one zip.');
        };
        foreach ($nodes as $n) {
            $n['name'] = self::dedupe($n['name'], $names);          // two chosen items with the same name must not overwrite each other in the zip
            $walk($n, '', 0);
        }
        return $out;
    }

    private static function dedupe(string $name, array &$seen): string
    {
        $try = $name; $i = 2;
        $ext = pathinfo($name, PATHINFO_EXTENSION); $base = $ext !== '' ? substr($name, 0, -(strlen($ext) + 1)) : $name;
        while (isset($seen[mb_strtolower($try)])) { $try = "{$base} ({$i})" . ($ext !== '' ? ".{$ext}" : ''); $i++; }
        $seen[mb_strtolower($try)] = true;
        return $try;
    }

    /** Makes a zip of the chosen items and keeps it as a new file in $parent. Returns the new file. @param list<int> $ids */
    public static function zip(int $owner, array $ids, ?int $parent): array
    {
        self::checkFolder($owner, $parent);
        $nodes = [];
        foreach ($ids as $id) $nodes[] = self::mine((int)$id, $owner) ?? throw new \InvalidArgumentException('An item was not found.');
        if (!$nodes) throw new \InvalidArgumentException('Choose something to zip first.');
        $entries = self::collect($nodes);
        $bytes = array_sum(array_map(fn($e) => $e['size'] ?? 0, $entries));
        if (self::totalUsed() + (int)($bytes * 0.3) > self::quotaBytes()) throw new \InvalidArgumentException('There is not enough storage space left to make that zip.');
        $name = count($nodes) === 1 ? $nodes[0]['name'] . '.zip' : (($parent !== null ? (self::get($parent)['name'] ?? 'Files') : 'Files') . '.zip');
        $tmp = self::root() . '/tmp/zip-' . bin2hex(random_bytes(6));
        $fh = fopen($tmp, 'wb');
        try { Zip::write($entries, static function (string $s) use ($fh): void { fwrite($fh, $s); }); } catch (\Throwable $e) { fclose($fh); @unlink($tmp); throw $e; }
        fclose($fh);
        $size = (int)filesize($tmp);
        if (self::totalUsed() + $size > self::quotaBytes()) { @unlink($tmp); throw new \InvalidArgumentException('There is not enough storage space left to keep that zip.'); }
        $blob = self::newBlob();
        $sha  = hash_file('sha256', $tmp);
        rename($tmp, self::blobPath($blob));
        return self::get(self::insert($owner, $parent, 'file', self::uniqueName($owner, $parent, $name, false), $size, 'application/zip', $blob, $sha));
    }

    /** Unpacks a zip into a new folder beside it. Returns the folder. Refuses anything suspicious and leaves nothing behind if it fails. */
    public static function unzip(int $owner, int $id): array
    {
        $n = self::mine($id, $owner) ?? throw new \InvalidArgumentException('That file was not found.');
        if ($n['kind'] !== 'file' || strtolower(pathinfo($n['name'], PATHINFO_EXTENSION)) !== 'zip') throw new \InvalidArgumentException('Only .zip files can be unzipped.');
        $file = self::blobPath((string)$n['blob']);
        try { $entries = Zip::entries($file); } catch (\RuntimeException $e) { throw new \InvalidArgumentException($e->getMessage()); }
        $plan = []; $declared = 0;
        foreach ($entries as $e) {
            $segs = Zip::safePath($e['name']);
            if ($segs === null) throw new \InvalidArgumentException('That zip contains a file path that is not allowed, so it was not unpacked.');
            if ($segs[0] === '__MACOSX' || end($segs) === '.DS_Store' || end($segs) === 'Thumbs.db') continue;
            if (count($segs) > 20) throw new \InvalidArgumentException('That zip has folders nested too deeply.');
            if (!$e['isDir']) {
                $declared += $e['usize'];
                if ($e['usize'] > self::MAX_FILE) throw new \InvalidArgumentException('A file in that zip is larger than the limit for one file.');
            }
            $plan[] = [$segs, $e];
        }
        if (!$plan) throw new \InvalidArgumentException('That zip is empty.');
        if ($declared > self::MAX_ZIP || self::totalUsed() + $declared > self::quotaBytes()) throw new \InvalidArgumentException('That zip is too big to unpack here.');

        $parent = $n['parent_id'] !== null ? (int)$n['parent_id'] : null;
        $top = self::mkdir($owner, $parent, preg_replace('/\.zip$/i', '', $n['name']) ?: 'Unzipped');
        $folders = ['' => $top];
        $ensure = function (array $segs) use (&$folders, $owner): int {
            $key = ''; $pid = $folders[''];
            foreach ($segs as $s) {
                $key .= '/' . mb_strtolower($s);
                if (!isset($folders[$key])) $folders[$key] = self::mkdir($owner, $pid, $s, true);
                $pid = $folders[$key];
            }
            return $pid;
        };
        $made = []; $written = 0;
        try {
            foreach ($plan as [$segs, $e]) {
                if ($e['isDir']) { $ensure($segs); continue; }
                $file_name = array_pop($segs);
                $pid = $ensure($segs);
                $tmp = self::root() . '/tmp/unz-' . bin2hex(random_bytes(6));
                $dest = fopen($tmp, 'wb');
                try { $size = Zip::extractEntry($file, $e, $dest, min(self::MAX_FILE, self::MAX_ZIP - $written)); }
                catch (\RuntimeException $x) { fclose($dest); @unlink($tmp); throw new \InvalidArgumentException($x->getMessage()); }
                fclose($dest);
                $written += $size;
                if (self::totalUsed() + $size > self::quotaBytes()) { @unlink($tmp); throw new \InvalidArgumentException('There is not enough storage space left to unpack that zip.'); }
                $blob = self::newBlob(); $sha = hash_file('sha256', $tmp);
                rename($tmp, self::blobPath($blob));
                $made[] = self::insert($owner, $pid, 'file', self::uniqueName($owner, $pid, $file_name, false), $size, self::mimeFor($file_name), $blob, $sha);
            }
        } catch (\Throwable $e) {
            self::delete($owner, [$top]);          // leave nothing half unpacked
            throw $e;
        }
        return self::get($top);
    }
}

/** An upload piece arrived out of order; $received is where the upload really is. */
final class UploadOutOfStep extends \RuntimeException
{
    public function __construct(public readonly int $received) { parent::__construct('The upload is out of step.'); }
}
