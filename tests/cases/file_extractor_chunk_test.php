<?php
// tests/cases/file_extractor_chunk_test.php
// Regression tests for FileExtractor::chunk()'s overlap behaviour - a
// fact sitting right at a chunk boundary must appear intact in at least
// one chunk, not split unretrievably across two.

declare(strict_types=1);

suite('Knowledge\FileExtractor — chunk()');

// Prefixed to avoid colliding with a same-named helper in another test
// file - all tests/cases/*.php files share one PHP process (see
// tests/run.php), so top-level function names must be unique across them.
function chunk_test_words(int $n, string $prefix = 'word'): string
{
    return implode(' ', array_map(fn($i) => "{$prefix}{$i}", range(1, $n)));
}

test('text shorter than the chunk size produces exactly one chunk', function () {
    $chunks = \Knowledge\FileExtractor::chunk(chunk_test_words(100), 500, 50);
    assert_count(1, $chunks);
    assert_equal(100, count(explode(' ', $chunks[0])));
});

test('text longer than the chunk size splits into multiple chunks', function () {
    $chunks = \Knowledge\FileExtractor::chunk(chunk_test_words(1200), 500, 50);
    assert_count(3, $chunks);
});

test('consecutive chunks overlap by the requested word count', function () {
    $chunks = \Knowledge\FileExtractor::chunk(chunk_test_words(1200), 500, 50);
    $first  = explode(' ', $chunks[0]);
    $second = explode(' ', $chunks[1]);

    // Last 50 words of chunk 1 == first 50 words of chunk 2.
    assert_equal(array_slice($first, -50), array_slice($second, 0, 50));
});

test('a word sitting at the boundary appears intact in at least one chunk', function () {
    // Put a distinctive marker right where a non-overlapping 500-word
    // chunker would have split (word 500/501) - regression guard for the
    // exact bug overlap exists to prevent.
    $words = array_map(fn($i) => "word{$i}", range(1, 1200));
    $words[499] = 'BOUNDARY-MARKER'; // 0-indexed word 500
    $text  = implode(' ', $words);

    $chunks = \Knowledge\FileExtractor::chunk($text, 500, 50);
    $found  = false;
    foreach ($chunks as $c) {
        if (str_contains($c, 'BOUNDARY-MARKER')) $found = true;
    }
    assert_true($found, 'expected the boundary marker to survive in at least one chunk');
});

test('an empty string produces no chunks', function () {
    assert_equal([], \Knowledge\FileExtractor::chunk('', 500, 50));
});

test('overlap larger than the chunk size does not loop forever or throw', function () {
    $chunks = \Knowledge\FileExtractor::chunk(chunk_test_words(600), 100, 500);
    assert_true(count($chunks) > 0 && count($chunks) < 1000, 'expected a bounded, sane chunk count');
});

suite('Knowledge\FileExtractor — chunkDocument()');

function datasheet_pages(): string {
    $pages = [];
    foreach (['4U' => '250', '6U' => '370', '4U ' => '250', '9U' => '505'] as $u => $d) {
        $u = trim($u);
        $pages[] = "NETWORKING | TECHNICAL DATA\nBlake QuikCab {$u}\nBLA-QUIKCAB-{$u} | Wall cabinet\n"
            . str_repeat("Flat-pack cabinet with lockable glass door and toolless sides. ", 6)
            . "\nRack capacity {$u}\nInstalled size 600 x 450 x {$d}mm\nRack format 19-inch\n"
            . "Blake UK Ltd | blake-uk.com | Issue 1 | Page 1";
    }
    return implode("\f", $pages);
}

test('one chunk per product page, labelled with document and page', function () {
    $c = \Knowledge\FileExtractor::chunkDocument(datasheet_pages(), 'Blake_QuikCab_Data_Sheets.pdf');
    assert_count(3, $c);   // duplicate 4U page dropped
    assert_true(str_starts_with($c[0], '[Blake QuikCab Data Sheets, page 1] '));
    assert_true(str_contains($c[0], 'Rack capacity 4U') && str_contains($c[0], 'x 250mm'));
    assert_false(str_contains($c[0], '6U'), 'no bleed into next product');
    assert_true(str_starts_with($c[2], '[Blake QuikCab Data Sheets, page 4] '));
});

test('running headers/footers are removed but repeated spec rows are kept', function () {
    $c = \Knowledge\FileExtractor::chunkDocument(datasheet_pages(), 'x.pdf');
    foreach ($c as $chunk) {
        assert_false(str_contains($chunk, 'TECHNICAL DATA'), 'header removed');
        assert_false(str_contains($chunk, 'Issue 1'), 'footer removed');
        assert_true(str_contains($chunk, 'Rack format 19-inch'), 'spec row kept');
    }
});

test('line-end hyphenation is rejoined and oversize chunks are split', function () {
    $c = \Knowledge\FileExtractor::chunkDocument("Four-through-bolt self-\nassembly system.", 'd.pdf');
    assert_true(str_contains($c[0], 'self-assembly'));
    $long = \Knowledge\FileExtractor::chunkDocument(str_repeat(str_repeat('A', 40) . ' ', 300), 'd.pdf');
    foreach ($long as $chunk) assert_true(mb_strlen($chunk) <= \Knowledge\FileExtractor::DOC_CHUNK_CHARS + 40);
    assert_true(count($long) > 1);
});
