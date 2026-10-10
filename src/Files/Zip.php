<?php
// src/Files/Zip.php: makes and reads zip files in plain PHP (no zip extension needed), streaming, so a large zip never has to
// fit in memory. It writes the usual "data descriptor" form that Windows, macOS and Linux all open.
// Reading is defensive: a zip from outside is treated as hostile (paths that climb out, huge expansions, damaged data).

declare(strict_types=1);

namespace Files;

final class Zip
{
    public const MAX_ENTRIES = 5000;
    private const ALREADY_COMPRESSED = ['zip', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'mp3', 'm4a', 'docx', 'xlsx', 'pptx', 'pdf', '7z', 'gz', 'rar'];

    /** @param list<array{name:string,path:?string,mtime?:int}> $entries a null path is a folder. $out receives the bytes. Returns the zip's size. */
    public static function write(array $entries, callable $out): int
    {
        $offset = 0; $central = ''; $count = 0;
        $emit = static function (string $s) use ($out, &$offset): void { $out($s); $offset += strlen($s); };
        foreach ($entries as $e) {
            $isDir = $e['path'] === null;
            $name  = $isDir ? rtrim($e['name'], '/') . '/' : $e['name'];
            $d = getdate(max((int)($e['mtime'] ?? time()), 315532800));
            $time = ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2);
            $date = (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'];
            $method = ($isDir || in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::ALREADY_COMPRESSED, true)) ? 0 : 8;
            $flags  = 0x0800 | ($isDir ? 0 : 0x0008);          // UTF-8 names; sizes follow the data
            $nameLen = strlen($name);
            $at = $offset;
            $emit(pack('VvvvvvVVVvv', 0x04034b50, 20, $flags, $method, $time, $date, 0, 0, 0, $nameLen, 0) . $name);
            $crc = 0; $csize = 0; $usize = 0;
            if (!$isDir) {
                $fh = @fopen($e['path'], 'rb');
                if (!$fh) throw new \RuntimeException('A file could not be read for the zip.');
                $hc = hash_init('crc32b');
                $dz = $method === 8 ? deflate_init(ZLIB_ENCODING_RAW, ['level' => 6]) : null;
                while (!feof($fh)) {
                    $chunk = fread($fh, 65536);
                    if ($chunk === false || $chunk === '') break;
                    $usize += strlen($chunk); hash_update($hc, $chunk);
                    $data = $dz ? deflate_add($dz, $chunk, ZLIB_NO_FLUSH) : $chunk;
                    if ($data !== '') { $csize += strlen($data); $emit($data); }
                }
                fclose($fh);
                if ($dz) { $data = deflate_add($dz, '', ZLIB_FINISH); if ($data !== '') { $csize += strlen($data); $emit($data); } }
                $crc = (int)hexdec(hash_final($hc));
                $emit(pack('VVVV', 0x08074b50, $crc, $csize, $usize));
            }
            if ($at > 0xFFFFFFFE || $csize > 0xFFFFFFFE || $usize > 0xFFFFFFFE) throw new \RuntimeException('That is too large for a zip file.');
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, $flags, $method, $time, $date, $crc, $csize, $usize, $nameLen, 0, 0, 0, 0, $isDir ? 0x10 : 0, $at) . $name;
            if (++$count > self::MAX_ENTRIES) throw new \RuntimeException('Too many files for one zip.');
        }
        $cd = $offset;
        $emit($central);
        $emit(pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $cd, 0));
        return $offset;
    }

    /** The files and folders inside a zip, from its table of contents. Throws if it is not a usable zip. @return list<array<string,mixed>> */
    public static function entries(string $file): array
    {
        $size = (int)@filesize($file);
        if ($size < 22) throw new \RuntimeException('That is not a zip file.');
        $fh = fopen($file, 'rb');
        $tail = min($size, 65557);
        fseek($fh, $size - $tail);
        $buf = (string)fread($fh, $tail);
        $pos = strrpos($buf, "PK\x05\x06");
        if ($pos === false || strlen($buf) < $pos + 22) { fclose($fh); throw new \RuntimeException('That is not a zip file.'); }
        $e = unpack('vdisk/vcddisk/vondisk/ventries/Vcdsize/Vcdoffset/vcomment', substr($buf, $pos + 4, 18));
        if ($e['entries'] === 0xFFFF || $e['cdoffset'] === 0xFFFFFFFF || $e['cdsize'] === 0xFFFFFFFF) { fclose($fh); throw new \RuntimeException('Very large (zip64) files are not supported.'); }
        if ($e['entries'] > self::MAX_ENTRIES) { fclose($fh); throw new \RuntimeException('That zip has too many files in it.'); }
        if ($e['cdoffset'] + $e['cdsize'] > $size) { fclose($fh); throw new \RuntimeException('That zip file is damaged.'); }
        fseek($fh, $e['cdoffset']);
        $cd = (string)fread($fh, $e['cdsize']);
        fclose($fh);
        $out = []; $p = 0;
        for ($i = 0; $i < $e['entries']; $i++) {
            if (strlen($cd) < $p + 46) throw new \RuntimeException('That zip file is damaged.');
            $h = unpack('Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Vxattr/Voffset', substr($cd, $p, 46));
            if ($h['sig'] !== 0x02014b50) throw new \RuntimeException('That zip file is damaged.');
            $name = substr($cd, $p + 46, $h['nlen']);
            $p += 46 + $h['nlen'] + $h['xlen'] + $h['clen'];
            if (!($h['flags'] & 0x0800) && !mb_check_encoding($name, 'UTF-8')) $name = (string)@iconv('CP437', 'UTF-8//IGNORE', $name);
            $out[] = ['name' => $name, 'isDir' => str_ends_with($name, '/'), 'method' => $h['method'], 'flags' => $h['flags'], 'crc' => $h['crc'],
                      'csize' => $h['csize'], 'usize' => $h['usize'], 'offset' => $h['offset']];
        }
        return $out;
    }

    /** The folders in a stored path, made safe: null if the name tries to escape or is otherwise unusable. @return ?list<string> */
    public static function safePath(string $name): ?array
    {
        $n = str_replace('\\', '/', $name);
        if ($n === '' || preg_match('/[\x00-\x1f]/', $n) || $n[0] === '/' || preg_match('~^[A-Za-z]:~', $n)) return null;
        $segs = array_values(array_filter(explode('/', $n), static fn($s) => $s !== '' && $s !== '.'));
        if (!$segs) return null;
        foreach ($segs as $s) if ($s === '..') return null;
        return $segs;
    }

    /** Writes one entry's contents to $dest, checking its size and checksum. Never writes more than $limit bytes. Returns the bytes written. */
    public static function extractEntry(string $file, array $entry, $dest, int $limit): int
    {
        if ($entry['flags'] & 0x0001) throw new \RuntimeException('Password-protected zips are not supported.');
        if (!in_array($entry['method'], [0, 8], true)) throw new \RuntimeException('That zip uses a compression method that is not supported.');
        if ($entry['usize'] > $limit) throw new \RuntimeException('A file in the zip is too large.');
        $fh = fopen($file, 'rb');
        fseek($fh, $entry['offset']);
        $l = unpack('Vsig/vver/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', (string)fread($fh, 30));
        if (!$l || $l['sig'] !== 0x04034b50) { fclose($fh); throw new \RuntimeException('That zip file is damaged.'); }
        fseek($fh, $entry['offset'] + 30 + $l['nlen'] + $l['xlen']);
        $left = $entry['csize']; $written = 0; $hc = hash_init('crc32b');
        $iz = $entry['method'] === 8 ? inflate_init(ZLIB_ENCODING_RAW) : null;
        $put = static function (string $data) use (&$written, $entry, $limit, $hc, $dest): void {
            $written += strlen($data);
            if ($written > $entry['usize'] || $written > $limit) throw new \RuntimeException('A file in the zip expands to more than it says, so it was refused.');
            hash_update($hc, $data); fwrite($dest, $data);
        };
        try {
            while ($left > 0) {
                $chunk = fread($fh, min(65536, $left));
                if ($chunk === false || $chunk === '') throw new \RuntimeException('That zip file is damaged.');
                $left -= strlen($chunk);
                if ($iz) {
                    $data = @inflate_add($iz, $chunk, ZLIB_NO_FLUSH);
                    if ($data === false) throw new \RuntimeException('That zip file is damaged.');
                    if ($data !== '') $put($data);
                } else $put($chunk);
            }
            if ($iz) { $data = @inflate_add($iz, '', ZLIB_FINISH); if ($data === false) throw new \RuntimeException('That zip file is damaged.'); if ($data !== '') $put($data); }
        } finally { fclose($fh); }
        if ($written !== $entry['usize'] || (int)hexdec(hash_final($hc)) !== $entry['crc']) throw new \RuntimeException('That zip file is damaged.');
        return $written;
    }
}
