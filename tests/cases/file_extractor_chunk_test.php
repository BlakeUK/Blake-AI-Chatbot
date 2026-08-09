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
