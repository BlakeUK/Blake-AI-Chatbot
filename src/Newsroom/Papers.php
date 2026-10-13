<?php
// src/Newsroom/Papers.php: a copy of a newspaper PDF kept on the server for two days, so there is always a real link to download it from,
// whatever the program or browser does about downloads. The page uploads the PDF in pieces; the link carries its own signature and expiry,
// so it needs no sign-in and cannot be guessed or altered; it stops working after 48 hours and the file is deleted.

declare(strict_types=1);

namespace Newsroom;

final class Papers
{
    public const MAX_BYTES = 40 * 1024 * 1024;
    public const CHUNK_MAX = 2 * 1024 * 1024;
    public const TTL = 172800;                 // 48 hours
    public const MAX_PER_PERSON = 5;

    public static function dir(): string
    {
        $d = dirname(\Files\Store::root()) . '/newsroom';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }

    private static function key(): string { return hash('sha256', 'paper|' . hex2bin(CFG['encrypt_key']), true); }
    private static function b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
    public static function sign(string $id, int $exp): string { return self::b64(hash_hmac('sha256', "{$id}|{$exp}", self::key(), true)); }

    private static function meta(string $id): ?array
    {
        if (!preg_match('/\A[0-9a-f]{24}\z/', $id) || !is_file(self::dir() . "/{$id}.meta")) return null;
        $m = json_decode((string)file_get_contents(self::dir() . "/{$id}.meta"), true);
        return is_array($m) ? $m : null;
    }

    /** Removes everything older than the time limit, finished or not. */
    public static function purge(?int $now = null): int
    {
        $n = 0; $now ??= time();
        foreach (glob(self::dir() . '/*') ?: [] as $f) if (is_file($f) && filemtime($f) < $now - self::TTL) { @unlink($f); $n++; }
        return $n;
    }

    /** Starts an upload. @return string the upload's id */
    public static function begin(int $adminId, int $size, string $name): string
    {
        if ($size < 100 || $size > self::MAX_BYTES) throw new \InvalidArgumentException('That file is too large or too small (the limit is ' . intdiv(self::MAX_BYTES, 1048576) . ' MB).');
        self::purge(); $mine = 0;
        foreach (glob(self::dir() . '/*.meta') ?: [] as $f) { $m = json_decode((string)file_get_contents($f), true); if (is_array($m) && (int)($m['admin'] ?? 0) === $adminId) $mine++; }
        if ($mine >= self::MAX_PER_PERSON) throw new \InvalidArgumentException('You already have ' . self::MAX_PER_PERSON . ' saved newspapers. They are removed after two days.');
        $name = trim((string)preg_replace('/-+\./', '.', (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', $name)), '-.'); $name = $name === '' ? 'newspaper.pdf' : substr($name, 0, 80);
        if (!str_ends_with(strtolower($name), '.pdf')) $name .= '.pdf';
        $id = bin2hex(random_bytes(12));
        file_put_contents(self::dir() . "/{$id}.meta", json_encode(['admin' => $adminId, 'size' => $size, 'name' => $name, 'done' => false]));
        touch(self::dir() . "/{$id}.part");
        return $id;
    }

    /** Adds the next piece, which must start exactly where the last ended. @return int bytes received so far */
    public static function chunk(string $id, int $adminId, int $offset, string $bytes): int
    {
        $m = self::meta($id);
        if (!$m || (int)$m['admin'] !== $adminId || $m['done']) throw new \InvalidArgumentException('That upload is not open.');
        if ($bytes === '' || strlen($bytes) > self::CHUNK_MAX) throw new \InvalidArgumentException('That piece is the wrong size.');
        $part = self::dir() . "/{$id}.part"; clearstatcache(true, $part); $have = (int)filesize($part);
        if ($offset !== $have) throw new \InvalidArgumentException('That piece is out of order.');
        if ($have + strlen($bytes) > (int)$m['size']) throw new \InvalidArgumentException('That is more than was announced.');
        file_put_contents($part, $bytes, FILE_APPEND | LOCK_EX);
        return $have + strlen($bytes);
    }

    /** Checks it is complete and really a PDF, and returns its signed address. @return array{id:string,exp:int,sig:string,name:string} */
    public static function finish(string $id, int $adminId, ?int $now = null): array
    {
        $m = self::meta($id);
        if (!$m || (int)$m['admin'] !== $adminId || $m['done']) throw new \InvalidArgumentException('That upload is not open.');
        $part = self::dir() . "/{$id}.part"; clearstatcache(true, $part);
        if ((int)filesize($part) !== (int)$m['size']) throw new \InvalidArgumentException('The file is not complete.');
        $fh = fopen($part, 'rb'); $head = (string)fread($fh, 5); fseek($fh, -1024, SEEK_END); $tail = (string)fread($fh, 1024); fclose($fh);
        if ($head !== '%PDF-' || !str_contains($tail, '%%EOF')) { @unlink($part); @unlink(self::dir() . "/{$id}.meta"); throw new \InvalidArgumentException('That is not a PDF file.'); }
        rename($part, self::dir() . "/{$id}.pdf"); $m['done'] = true; file_put_contents(self::dir() . "/{$id}.meta", json_encode($m));
        $exp = ($now ?? time()) + self::TTL;
        return ['id' => $id, 'exp' => $exp, 'sig' => self::sign($id, $exp), 'name' => $m['name']];
    }

    /** The file behind a signed address, or null if the address is wrong, altered, expired or the file is gone. @return array{path:string,name:string}|null */
    public static function open(string $id, int $exp, string $sig, ?int $now = null): ?array
    {
        if ($exp < ($now ?? time()) || !hash_equals(self::sign($id, $exp), $sig)) return null;
        $m = self::meta($id); $path = self::dir() . "/{$id}.pdf";
        return ($m && $m['done'] && is_file($path)) ? ['path' => $path, 'name' => (string)$m['name']] : null;
    }
}
