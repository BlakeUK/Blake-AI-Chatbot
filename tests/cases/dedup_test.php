<?php
// tests/cases/dedup_test.php
// Regression tests for src/Knowledge/Dedup.php - exact-duplicate blocking
// (deterministic, safe to automate) and near-duplicate flagging (fuzzy,
// deliberately never auto-deletes - only ever flagged for admin review).

declare(strict_types=1);

function dedup_seed_file(string $filename, string $chunkText, ?string $contentHash = null): int
{
    $pdo = db();
    $pdo->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status, content_hash) VALUES (?, 'application/pdf', '/tmp/x', 'indexed', ?)")
        ->execute([$filename, $contentHash]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('INSERT INTO knowledge_chunks (source_type, source_id, chunk_text) VALUES (?,?,?)')
        ->execute(['file', $id, $chunkText]);
    return $id;
}

suite('Knowledge\Dedup — hashing');

test('hashBytes is stable for identical bytes', function () {
    assert_equal(\Knowledge\Dedup::hashBytes('hello world'), \Knowledge\Dedup::hashBytes('hello world'));
});

test('hashBytes differs for different bytes', function () {
    assert_false(\Knowledge\Dedup::hashBytes('hello world') === \Knowledge\Dedup::hashBytes('hello worlds'));
});

test('hashText normalises whitespace and case before hashing', function () {
    $a = \Knowledge\Dedup::hashText('  Hello   World  ');
    $b = \Knowledge\Dedup::hashText('hello world');
    assert_equal($a, $b);
});

suite('Knowledge\Dedup — exact file duplicates');

test('finds an existing indexed file with the same content hash', function () {
    $hash = \Knowledge\Dedup::hashBytes('warranty-leaflet-v1-bytes');
    $id   = dedup_seed_file('warranty.pdf', 'Warranty terms and conditions.', $hash);

    $found = \Knowledge\Dedup::findExactFileDuplicate($hash);
    assert_true($found !== null);
    assert_equal($id, $found['id']);
});

test('returns null when no file has that content hash', function () {
    assert_null(\Knowledge\Dedup::findExactFileDuplicate(\Knowledge\Dedup::hashBytes('never-uploaded-bytes')));
});

test('a file stuck in error status is not treated as an existing duplicate', function () {
    $pdo = db();
    $hash = \Knowledge\Dedup::hashBytes('failed-extraction-bytes');
    $pdo->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status, content_hash) VALUES ('bad.pdf', 'application/pdf', '/tmp/x', 'error', ?)")
        ->execute([$hash]);
    assert_null(\Knowledge\Dedup::findExactFileDuplicate($hash));
});

suite('Knowledge\Dedup — exact entry duplicates');

test('finds an existing active entry with the same normalised text', function () {
    $pdo  = db();
    $hash = \Knowledge\Dedup::hashText('Returns Policy Returns accepted within 30 days.');
    $pdo->prepare("INSERT INTO knowledge_entries (title, body, active, source, content_hash) VALUES ('Returns Policy', 'Returns accepted within 30 days.', 1, 'manual', ?)")
        ->execute([$hash]);
    $id = (int)$pdo->lastInsertId();

    $found = \Knowledge\Dedup::findExactEntryDuplicate($hash);
    assert_equal($id, $found['id']);
});

test('excludeId lets an entry check for duplicates against others without matching itself', function () {
    $pdo  = db();
    $hash = \Knowledge\Dedup::hashText('Delivery Info Standard delivery 2-4 days.');
    $pdo->prepare("INSERT INTO knowledge_entries (title, body, active, source, content_hash) VALUES ('Delivery Info', 'Standard delivery 2-4 days.', 1, 'manual', ?)")
        ->execute([$hash]);
    $id = (int)$pdo->lastInsertId();

    assert_null(\Knowledge\Dedup::findExactEntryDuplicate($hash, $id));
    assert_true(\Knowledge\Dedup::findExactEntryDuplicate($hash) !== null);
});

test('an inactive entry is not treated as an existing duplicate', function () {
    $pdo  = db();
    $hash = \Knowledge\Dedup::hashText('Archived Notice This is old.');
    $pdo->prepare("INSERT INTO knowledge_entries (title, body, active, source, content_hash) VALUES ('Archived', 'This is old.', 0, 'manual', ?)")
        ->execute([$hash]);
    assert_null(\Knowledge\Dedup::findExactEntryDuplicate($hash));
});

suite('Knowledge\Dedup — near-duplicates (flag for review, never delete)');

test('finds a near-duplicate via shared vocabulary above the threshold', function () {
    $existingId = dedup_seed_file(
        'warranty-original.pdf',
        'All Blake UK products come with a minimum two year manufacturer warranty covering parts and labour for manufacturing defects.'
    );

    $newText = 'All Blake UK products come with a minimum two year manufacturer warranty covering parts and labour for manufacturing faults.';
    $matches = \Knowledge\Dedup::findNearDuplicates($newText, 'file', 999999);

    $found = false;
    foreach ($matches as $m) {
        if ($m['source_type'] === 'file' && $m['source_id'] === $existingId) $found = true;
    }
    assert_true($found, 'expected the near-identical warranty text to be flagged as a near duplicate');
});

test('does not flag genuinely different content as a near-duplicate', function () {
    dedup_seed_file('cctv-specs.pdf', 'The 4K camera kit supports night vision up to thirty metres and local SD card storage.');

    $matches = \Knowledge\Dedup::findNearDuplicates(
        'Standard UK delivery takes between two and four working days for most postcodes.',
        'file', 999999
    );
    assert_equal([], $matches);
});

test('short text is never flagged - too little signal to compare meaningfully', function () {
    dedup_seed_file('short.pdf', 'Blake UK warranty terms apply to all products sold.');
    assert_equal([], \Knowledge\Dedup::findNearDuplicates('Blake UK warranty', 'file', 999999));
});

test('excludes the source itself from its own near-duplicate results', function () {
    $id = dedup_seed_file('self.pdf', 'This exact same paragraph should never be flagged against itself in the results here.');
    $matches = \Knowledge\Dedup::findNearDuplicates(
        'This exact same paragraph should never be flagged against itself in the results here.',
        'file', $id
    );
    foreach ($matches as $m) {
        assert_false($m['source_type'] === 'file' && $m['source_id'] === $id);
    }
});

suite('Knowledge\Dedup — flag()');

test('flag() records a pending flag', function () {
    \Knowledge\Dedup::flag('file', 111, [['source_type' => 'file', 'source_id' => 222, 'similarity' => 0.75]]);

    $row = db()->prepare("SELECT similarity, status FROM knowledge_duplicate_flags WHERE source_type='file' AND source_id=111 AND similar_source_id=222");
    $row->execute();
    $r = $row->fetch();
    assert_equal(0.75, $r['similarity']);
    assert_equal('pending', $r['status']);
});

test('flag() does not create a second pending flag for the same pair', function () {
    \Knowledge\Dedup::flag('file', 333, [['source_type' => 'file', 'source_id' => 444, 'similarity' => 0.8]]);
    \Knowledge\Dedup::flag('file', 333, [['source_type' => 'file', 'source_id' => 444, 'similarity' => 0.8]]);

    $count = db()->prepare("SELECT COUNT(*) FROM knowledge_duplicate_flags WHERE source_type='file' AND source_id=333 AND similar_source_id=444");
    $count->execute();
    assert_equal(1, (int)$count->fetchColumn());
});

suite('Knowledge\PageIndexer — dedup integration');

test('indexPage() stores a content_hash on the entry', function () {
    $r = \Knowledge\PageIndexer::indexPage(
        'https://www.blake-uk.com/dedup-hash-test',
        '<html><head><title>Hash Test</title></head><body><p>Some genuinely unique page content for the hash test.</p></body></html>'
    );
    $row = db()->prepare('SELECT content_hash FROM knowledge_entries WHERE id = ?');
    $row->execute([$r['id']]);
    assert_true(!empty($row->fetchColumn()));
});

test('indexPage() flags a near-duplicate against an existing file without blocking the import', function () {
    dedup_seed_file(
        'aerial-guide.pdf',
        'Our engineers install TV aerials and satellite dishes across the whole of the United Kingdom with a full workmanship guarantee included.'
    );

    $r = \Knowledge\PageIndexer::indexPage(
        'https://www.blake-uk.com/dedup-page-test',
        '<html><head><title>Aerial Installation</title></head><body><p>Our engineers install TV aerials and satellite dishes across the whole of the United Kingdom with a full workmanship guarantee provided.</p></body></html>'
    );

    assert_equal('imported', $r['status']); // not blocked

    $count = db()->prepare("SELECT COUNT(*) FROM knowledge_duplicate_flags WHERE source_type='manual' AND source_id=?");
    $count->execute([$r['id']]);
    assert_true((int)$count->fetchColumn() > 0, 'expected a near-duplicate flag to have been recorded');
});
