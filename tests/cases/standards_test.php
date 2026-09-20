<?php
// tests/cases/standards_test.php
// DVB/ETSI technical standards tier: clause chunking, "very technical"
// gate, import of the bundled set, and isolation from normal retrieval.

declare(strict_types=1);

suite('Knowledge\Standards — DVB/ETSI technical tier');

test('chunker: one chunk per clause, labelled for citation; boilerplate, contents and references dropped', function () {
    $raw = "Contents\nIntellectual Property Rights ........................ 5\n1   Scope .................. 7\n\fIntellectual Property Rights\nPatent text that must not be indexed.\n\nForeword\nThis European Standard has been produced by JTC.\n\n1        Scope\nThe present document specifies the DVB-T2 system for digital terrestrial television, including framing, channel coding\nand modulation for broadcast of MPEG transport streams and generic streams to fixed, portable and mobile receivers.\n\n2        References\n[1] ETSI EN 300 744 something.\n\n                                   5            ETSI EN 302 755 V1.4.1 (2015-07)\n8.3.1        Duration of the T2-Frame\nThe transmitter shall select the number of data symbols per T2-frame so that the frame duration does not exceed 250 ms. The duration of\nthe T2-frame is given by the number of symbols multiplied by the OFDM symbol duration including the guard interval.\n                                                              ETSI\nHistory\nV1.4.1   2015-07   Publication\n";
    $c = \Knowledge\StandardsChunker::chunk($raw, ['code' => 'ETSI EN 302 755', 'short' => 'DVB-T2']);
    $all = implode("\n", array_column($c, 'text'));
    assert_equal(['1', '8.3.1'], array_column($c, 'clause'));
    assert_true(str_starts_with($c[1]['text'], '[ETSI EN 302 755 (DVB-T2), clause 8.3.1 Duration of the T2-Frame]'));
    foreach (['Patent text', 'produced by JTC', 'EN 300 744 something', 'V1.4.1 (2015-07)', 'Publication', '......'] as $bad) {
        assert_true(!str_contains($all, $bad), "should be dropped: {$bad}");
    }
});

test('very technical gate: standards vocabulary yes, everyday questions no', function () {
    foreach (['Which FFT size and guard interval suit an SFN?', 'what MER should I see on DVB-T2 at the outlet', 'IPTV multicast with IGMP snooping on the switch',
              'symbol rate and roll-off for a DVB-S2 transponder', 'does the receiver need HEVC for UHD', 'what PID carries the EIT'] as $q) {
        assert_true(\Knowledge\Standards::isVeryTechnical($q), "technical: {$q}");
    }
    foreach (['my TV has lost some channels', 'which aerial do I need for S3 9PT', 'do you sell CAT6 patch leads', 'why is my picture breaking up', 'how much is delivery'] as $q) {
        assert_true(!\Knowledge\Standards::isVeryTechnical($q), "not technical: {$q}");
    }
});

test('bundled set imports all nine documents with thousands of clause chunks', function () {
    $r = \Knowledge\Standards::import();
    assert_equal(9, $r['documents']);
    assert_true($r['chunks'] > 2000, 'chunks: ' . $r['chunks']);
    $codes = array_column(\Knowledge\Standards::documents(), 'short');
    foreach (['DVB-T', 'DVB-T2', 'DVB-S2', 'DVB-C', 'DVB-SI', 'DVB-IPTV', 'DVB AV coding'] as $s) assert_true(in_array($s, $codes, true), $s);
    $again = \Knowledge\Standards::import();
    assert_equal($r['chunks'], (int)db()->query("SELECT COUNT(*) FROM knowledge_chunks WHERE source_type = 'standard'")->fetchColumn(), 're-import replaces, never duplicates');
});

test('standards never appear in ordinary knowledge search', function () {
    \Knowledge\Embeddings::resetCaches();
    foreach (\Knowledge\Search::query('guard interval FFT OFDM T2-frame', 5) as $h) {
        assert_true($h['source_type'] !== 'standard', 'standard chunk leaked into normal search');
    }
    $s = \Knowledge\Standards::search('guard interval FFT OFDM T2-frame', 3);
    assert_true(count($s) > 0 && str_contains($s[0]['chunk_text'], '[ETSI'));
});

test('buildContext adds standards only for very technical questions, and the prompt says how to use them', function () {
    \Knowledge\Embeddings::resetCaches();
    $tech = \Chat\Responder::buildContext('Which FFT mode and guard interval does DVB-T2 use for a large SFN?', null, '');
    assert_true(count($tech['standard_hits']) > 0);
    $p = \Chat\Responder::buildPrompt($tech, null, null);
    assert_true(str_contains($p, 'TECHNICAL STANDARDS (DVB/ETSI'));
    assert_true(str_contains($p, 'cite them by number and clause'));
    $plain = \Chat\Responder::buildContext('why is my TV picture breaking up', null, '');
    assert_equal([], $plain['standard_hits']);
    assert_true(!str_contains(\Chat\Responder::buildPrompt($plain, null, null), 'TECHNICAL STANDARDS (DVB/ETSI'));
});
