<?php
// tests/cases/file_extractor_pdf_test.php
// Knowledge\FileExtractor: local PDF text-layer extraction (pdftotext) and
// the RECITATION fallback detection.

declare(strict_types=1);

suite('Knowledge\FileExtractor — PDF text layer and RECITATION fallback');

$hasPdftotext = trim((string)shell_exec('command -v pdftotext 2>/dev/null')) !== '';

test('isRecitation(): matches the Gemini RECITATION refusal only', function () {
    assert_true(\Knowledge\FileExtractor::isRecitation('Gemini returned no answer text (reason: RECITATION)'));
    assert_true(!\Knowledge\FileExtractor::isRecitation('Gemini API error 429 (model: m): quota'));
});

test('usableText(): rejects thin or non-textual output, normalises layout padding', function () {
    assert_equal(null, \Knowledge\FileExtractor::usableText("  \f \n 12 "));
    assert_equal(null, \Knowledge\FileExtractor::usableText(str_repeat('1234 5678 | ', 40)));
    $t = \Knowledge\FileExtractor::usableText(str_repeat("Supply voltage        12V DC\n\n\n\n", 12) . "\f");
    assert_true($t !== null);
    assert_true(!str_contains($t, '        '), 'layout padding collapsed');
    assert_true(!str_contains($t, "\n\n\n"), 'blank runs collapsed');
});

test('pdfTextLayer(): reads a text PDF locally, returns null for an image-only PDF', function () use ($hasPdftotext) {
    if (!$hasPdftotext) { assert_true(true); return; }
    $root = dirname(__DIR__);
    $t = \Knowledge\FileExtractor::pdfTextLayer("$root/fixtures/text_layer.pdf");
    assert_true($t !== null && str_contains($t, 'M6 bolts') && str_contains($t, '0 to 20 dB'), 'expected manual text');
    assert_equal(null, \Knowledge\FileExtractor::pdfTextLayer("$root/fixtures/no_text_layer.pdf"));
});

test('extract(): a text PDF is indexed without any Gemini call or API key', function () use ($hasPdftotext) {
    if (!$hasPdftotext) { assert_true(true); return; }
    $pdo  = db();
    $path = dirname(__DIR__) . '/fixtures/text_layer.pdf';
    $pdo->prepare('INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES (?,?,?,?)')
        ->execute(['IR40_test.pdf', 'application/pdf', $path, 'pending']);
    $id = (int)$pdo->lastInsertId();
    $before = (int)$pdo->query("SELECT COUNT(*) FROM api_usage_log WHERE service = 'gemini'")->fetchColumn();
    assert_equal(null, \Knowledge\FileExtractor::extract($id, $path, 'application/pdf'));
    $s = $pdo->prepare('SELECT status FROM knowledge_files WHERE id = ?'); $s->execute([$id]);
    assert_equal('indexed', $s->fetchColumn());
    $c = $pdo->prepare("SELECT GROUP_CONCAT(chunk_text, ' ') FROM knowledge_chunks WHERE source_type = 'file' AND source_id = ?"); $c->execute([$id]);
    assert_true(str_contains((string)$c->fetchColumn(), 'F-type connector'));
    assert_equal($before, (int)$pdo->query("SELECT COUNT(*) FROM api_usage_log WHERE service = 'gemini'")->fetchColumn());
});
