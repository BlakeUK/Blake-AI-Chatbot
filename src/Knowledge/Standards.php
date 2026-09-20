<?php
// src/Knowledge/Standards.php
// Technical standards tier: DVB/ETSI specifications (DVB-T/T2, DVB-S2,
// DVB-C, DVB-SI, AV coding, DVB-IPTV) split into clause chunks
// (scripts/standards/, StandardsChunker). Kept apart from Blake UK's own
// knowledge: normal retrieval never returns them. They are searched only
// when a question is VERY technical (isVeryTechnical), and the prompt
// tells Max to prefer Blake UK content, explain in his own words and cite
// the standard and clause.

declare(strict_types=1);

namespace Knowledge;

class Standards
{
    // Standards-level vocabulary: any one of these marks a question as very
    // technical. Kept specific on purpose - everyday words ("signal",
    // "channels", "HD") must not pull in the standards.
    private const STRONG = [
        'dvb-t', 'dvb-t2', 'dvb-s', 'dvb-s2', 'dvb-s2x', 'dvb-c', 'dvb-c2', 'dvb-si', 'dvb-iptv', 't2-lite', 't2 lite',
        'fft', 'guard interval', 'code rate', 'coderate', 'ldpc', 'bch', 'plp', 'pilot pattern', 'sfn', 'mfn', 'miso', 'mimo',
        'mer', 'ber', 'c/n', 'cnr', 'es/no', 'eb/no', 'symbol rate', 'roll-off', 'rolloff', 'constellation',
        'qpsk', '8psk', '16apsk', '32apsk', 'apsk', 'qam', '16-qam', '64-qam', '256-qam', 'ofdm', 'cofdm', 'interleav',
        'transport stream', 'mpeg-ts', 'mpeg ts', 'pid', 'nit', 'sdt', 'eit', 'pmt', 'pat', 'bat', 'tdt', 'tot', 'lcn',
        'descriptor', 'service_id', 'network_id', 'onid', 'tsid',
        'hevc', 'h.265', 'h.264', 'avc', 'e-ac-3', 'ac-3', 'ac-4', 'he-aac', 'aac', 'uhdtv', 'hdr10', 'hlg',
        'igmp', 'multicast', 'rtp', 'rtsp', 'udp', 'pro-mpeg', 'cop3', 'fec', 'dvbstp', 'sd&s', 'fast channel change',
        'acm', 'vcm', 'ccm', 'baseband frame', 'bbframe', 'etsi', 'en 300', 'en 302', 'ts 101', 'ts 102', 'tr 102',
        '8k mode', '32k', '16k', '1k mode', '2k mode', 'rotated constellation', 'papr', 'tfs',
    ];
    // Weaker signals: two or more together also count.
    private const MODERATE = ['bitrate', 'bit rate', 'mbit', 'modulation', 'multiplex', 'mux', 'bandwidth', 'transponder',
        'polarisation', 'polarization', 'snr', 'dbµv', 'dbuv', 'dbmv', 'mhz', 'khz', 'spectrum', 'carrier', 'demodulat',
        'encoder', 'decoder', 'codec', 'stream', 'packet', 'jitter', 'latency', 'ip tv', 'iptv', 'headend', 'head-end'];

    public static function isVeryTechnical(string $text): bool
    {
        $t = ' ' . mb_strtolower($text) . ' ';
        foreach (self::STRONG as $w) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($w, '/') . '(?![a-z0-9])/u', $t)) return true;
        }
        $n = 0;
        foreach (self::MODERATE as $w) {
            if (preg_match('/(?<![a-z0-9])' . preg_quote($w, '/') . '/u', $t)) $n++;
        }
        return $n >= 2;
    }

    // Replace all standard chunks with the bundled set. Returns counts.
    public static function import(?string $path = null): array
    {
        $path = $path ?? ROOT . '/scripts/standards/chunks.json.gz';
        $data = json_decode((string)gzdecode((string)file_get_contents($path)), true);
        if (!is_array($data) || empty($data['documents'])) throw new \RuntimeException("No standards data in {$path}");
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM knowledge_chunks WHERE source_type = 'standard'");
            $pdo->exec('DELETE FROM standards_documents');
            $doc = $pdo->prepare('INSERT INTO standards_documents (code, version, short, title, topic, url, chunk_count, imported_at) VALUES (?,?,?,?,?,?,?,?)');
            $ins = $pdo->prepare("INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url, category) VALUES ('standard', ?, ?, ?, ?)");
            $total = 0;
            foreach ($data['documents'] as $d) {
                $doc->execute([$d['code'], $d['version'] ?? null, $d['short'], $d['title'] ?? null, $d['topic'] ?? null, $d['url'] ?? null, count($d['chunks']), time()]);
                $id = (int)$pdo->lastInsertId();
                foreach ($d['chunks'] as $c) {
                    $ins->execute([$id, $c['text'], $d['url'] ?? null, $d['short']]);
                    $total++;
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['documents' => count($data['documents']), 'chunks' => $total];
    }

    public static function documents(): array
    {
        try {
            return db()->query('SELECT id, code, version, short, title, topic, url, chunk_count, imported_at FROM standards_documents ORDER BY topic, code')->fetchAll();
        } catch (\Throwable $e) { return []; }
    }

    // Hybrid (BM25 + semantic) search over standard chunks only.
    public static function search(string $query, int $limit = 3): array
    {
        $cols = 'kc.id, kc.source_type, kc.source_id, kc.chunk_text, kc.url, kc.category';
        $clean = Search::ftsOr($query);
        $rows = [];
        if ($clean !== '') {
            try {
                $st = db()->prepare("SELECT {$cols} FROM knowledge_fts JOIN knowledge_chunks kc ON kc.id = knowledge_fts.rowid
                                     WHERE knowledge_fts MATCH ? AND kc.source_type = 'standard' ORDER BY rank LIMIT 20");
                $st->execute([$clean]);
                $rows = $st->fetchAll();
            } catch (\Throwable $e) { $rows = []; }
        }
        $qv = Embeddings::queryVector($query);
        if ($qv === null) return array_slice($rows, 0, $limit);

        $byId = [];
        foreach ($rows as $r) $byId[(string)$r['id']] = $r;
        $bm25 = array_keys($byId);
        $vec = Embeddings::nearest($qv, 'standard', 20);
        $missing = array_values(array_diff(array_map('strval', array_keys($vec)), array_map('strval', $bm25)));
        if ($missing) {
            $in = implode(',', array_fill(0, count($missing), '?'));
            $st = db()->prepare("SELECT {$cols} FROM knowledge_chunks kc WHERE kc.id IN ({$in}) AND kc.source_type = 'standard'");
            $st->execute(array_map('intval', $missing));
            foreach ($st->fetchAll() as $r) $byId[(string)$r['id']] = $r;
        }
        $out = [];
        foreach (Embeddings::fuse(array_map('strval', $bm25), array_map('strval', array_keys($vec))) as $id) {
            if (isset($byId[$id])) $out[] = $byId[$id];
            if (count($out) >= $limit) break;
        }
        return $out;
    }
}
