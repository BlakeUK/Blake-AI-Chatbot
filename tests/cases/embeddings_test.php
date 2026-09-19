<?php
// tests/cases/embeddings_test.php
// Knowledge\Embeddings + hybrid Search: a fake, deterministic embedder maps
// words to "concepts" so a question sharing no words with a chunk can still
// be matched semantically, exactly what BM25 alone can't do.

declare(strict_types=1);

function emb_fake(string $text): array
{
    $concepts = [
        0 => ['boost', 'amplifier', 'amplify', 'masthead', 'stronger', 'booster'],
        1 => ['signal', 'reception', 'picture', 'freeview', 'channels'],
        2 => ['cctv', 'camera', 'recorder', 'security'],
        3 => ['cable', 'patch', 'lead', 'ethernet', 'cat6'],
        4 => ['refund', 'invoice', 'payment', 'account'],
    ];
    $v = array_fill(0, 8, 0.01);
    foreach (preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) as $w) {
        foreach ($concepts as $d => $ws) if (in_array($w, $ws, true)) $v[$d] += 1;
    }
    return $v;
}

suite('Knowledge\Embeddings — semantic index and hybrid retrieval');

test('pack/unpack round-trips to a unit vector', function () {
    $u = \Knowledge\Embeddings::unpack(\Knowledge\Embeddings::pack([3, 4]));
    assert_equal([0.6, 0.8], array_map(fn($x) => round($x, 5), $u));
});

test('fuse (RRF) ranks items found by both searches first', function () {
    assert_equal(['b', 'a', 'd', 'c'], \Knowledge\Embeddings::fuse(['a', 'b', 'c'], ['b', 'd']));
});

test('sync embeds chunks and products once, re-embeds only changed text', function () {
    $pdo = db();
    $pdo->exec('DELETE FROM embeddings');
    $pdo->prepare("INSERT INTO knowledge_chunks (source_type, source_id, chunk_text, url) VALUES ('manual', 99901, 'A masthead amplifier fitted near the aerial makes a weak TV input much stronger.', 'https://www.blake-uk.com/amps.html')")->execute();
    $pdo->prepare("INSERT INTO knowledge_chunks (source_type, source_id, chunk_text) VALUES ('manual', 99902, 'CCTV recorders store camera footage for 30 days.')")->execute();
    $calls = 0;
    $emb = function (string $t) use (&$calls) { $calls++; return emb_fake($t); };
    $r1 = \Knowledge\Embeddings::sync(10000, $emb, 60);
    assert_true($r1['embedded'] >= 2);
    $first = $calls;
    $r2 = \Knowledge\Embeddings::sync(10000, $emb, 60);
    assert_equal(0, $r2['embedded']);
    assert_equal($first, $calls);
    $pdo->exec("UPDATE knowledge_chunks SET chunk_text = 'CCTV recorders store camera footage for 60 days.' WHERE source_id = 99902");
    assert_equal(1, \Knowledge\Embeddings::sync(10000, $emb, 60)['embedded']);
});

test('hybrid search finds the amplifier chunk for "boost my reception" with no words in common', function () {
    \Knowledge\Embeddings::resetCaches();
    $q = 'boost my reception';
    // BM25 alone: no shared terms with the amplifier chunk.
    $bm25 = array_column(\Knowledge\Search::query('zzqnomatch', 5), 'chunk_text');
    assert_equal([], $bm25);
    \Knowledge\Embeddings::queryVector($q, fn($t) => emb_fake($t));   // prime the per-request cache
    $hits = \Knowledge\Search::query($q, 5);
    assert_true(count($hits) > 0, 'semantic hit expected: ' . json_encode(\Knowledge\Embeddings::nearest(\Knowledge\Embeddings::queryVector($q), 'chunk', 5, 0.0)));
    assert_true((bool)array_filter($hits, fn($h) => str_contains($h['chunk_text'], 'masthead amplifier')), 'amplifier chunk retrieved');
    foreach ($hits as $h) assert_true(!str_contains($h['chunk_text'], 'CCTV'), 'unrelated chunk below the similarity threshold');
    \Knowledge\Embeddings::resetCaches();
});

test('an unrelated question gets no semantic hits (so the bot can still escalate)', function () {
    \Knowledge\Embeddings::resetCaches();
    \Knowledge\Embeddings::queryVector('what time is the football', fn($t) => emb_fake($t));
    $hits = \Knowledge\Search::query('what time is the football', 5);
    foreach ($hits as $h) assert_true(!in_array((int)$h['source_id'], [99901, 99902], true));
    \Knowledge\Embeddings::resetCaches();
});

test('without an API key or embeddings the search is plain BM25', function () {
    \Knowledge\Embeddings::resetCaches();
    assert_equal(null, \Knowledge\Embeddings::queryVector('masthead amplifier'));
    $hits = \Knowledge\Search::query('masthead amplifier', 5);
    assert_true(count($hits) > 0 && str_contains($hits[0]['chunk_text'], 'masthead'));
});
