<?php
// tests/cases/speaker_test.php
// Speech\Speaker (spoken summary text, WAV wrapping) and
// Gemini\Client::extractAudio().

declare(strict_types=1);

suite('Speech\Speaker — spoken summaries and audio');

test('speakable(): strips URLs, markdown links and formatting', function () {
    $t = \Speech\Speaker::speakable("**Yes!** See [Yagi aerials](https://www.blake-uk.com/category/aerials-tv-yagi.html) or https://www.blake-uk.com/x.html.\n- Item one");
    assert_true(!str_contains($t, 'http'), 'no URL');
    assert_true(!str_contains($t, '*') && !str_contains($t, '['), 'no markdown');
    assert_true(str_contains($t, 'Yagi aerials') && str_contains($t, 'Item one'));
});

test('hasLinks(): detects bare and markdown links', function () {
    assert_true(\Speech\Speaker::hasLinks('see https://a.b/c'));
    assert_true(\Speech\Speaker::hasLinks('see [x](/y)'));
    assert_true(!\Speech\Speaker::hasLinks('no links here'));
});

test('fallbackSummary(): two sentences max, word-capped, links line appended, never reads a URL', function () {
    $reply = 'Yagi aerials suit weak signal areas. Point it at your transmitter. Use a mast bracket. Third sentence here. '
           . 'See https://www.blake-uk.com/category/aerials-tv-yagi.html';
    $s = \Speech\Speaker::fallbackSummary($reply);
    assert_true(str_starts_with($s, 'Yagi aerials suit weak signal areas. Point it at your transmitter.'));
    assert_true(!str_contains($s, 'mast bracket'));
    assert_true(str_ends_with($s, \Speech\Speaker::LINKS_LINE));
    assert_true(!str_contains($s, 'http'));
    $long = str_repeat('word ', 200) . '.';
    assert_true(str_word_count(\Speech\Speaker::fallbackSummary($long)) <= \Speech\Speaker::MAX_WORDS + 1);
});

test('spokenSummary(): with no API key falls back deterministically', function () {
    $s = \Speech\Speaker::spokenSummary('We stock satellite dishes. See [dishes](https://www.blake-uk.com/category/satellite-dishes.html).');
    assert_true(str_contains($s, 'satellite dishes'));
    assert_true(str_ends_with($s, \Speech\Speaker::LINKS_LINE));
});

test('wav(): valid 44-byte RIFF header for 24 kHz mono 16-bit PCM', function () {
    $pcm = str_repeat("\x00\x01", 2400);
    $w = \Speech\Speaker::wav($pcm, 24000);
    assert_equal(44 + strlen($pcm), strlen($w));
    assert_equal('RIFF', substr($w, 0, 4));
    assert_equal('WAVE', substr($w, 8, 4));
    $f = unpack('Vsize/vfmt/vch/Vrate/Vbyterate/vblock/vbits', substr($w, 16, 20));
    assert_equal([16, 1, 1, 24000, 48000, 2, 16], array_values($f));
    assert_equal(strlen($pcm), unpack('V', substr($w, 40, 4))[1]);
});

test('Client::extractAudio(): decodes inline PCM and sample rate, throws when absent', function () {
    $a = \Gemini\Client::extractAudio(['candidates' => [['content' => ['parts' => [['inlineData' => ['mimeType' => 'audio/L16;codec=pcm;rate=16000', 'data' => base64_encode('abcd')]]]]]]]);
    assert_equal(['pcm' => 'abcd', 'rate' => 16000], $a);
    $threw = false;
    try { \Gemini\Client::extractAudio(['candidates' => [['finishReason' => 'OTHER']]]); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'OTHER'); }
    assert_true($threw);
});

test('settings(): defaults to enabled, male Charon voice, radio-presenter style, no Yorkshire accent', function () {
    $c = \Speech\Speaker::settings();
    assert_true($c['enabled']);
    assert_equal('Charon', $c['voice']);
    assert_true(str_contains($c['style'], 'radio presenter'));
    assert_true(!str_contains($c['style'], 'Yorkshire'));
    foreach (\Speech\Speaker::OLD_STYLES as $old) { assert_true($old !== \Speech\Speaker::DEFAULT_STYLE); }
    assert_true(in_array($c['voice'], \Speech\Speaker::MALE_VOICES, true));
});

test('settings(): a stored earlier default style is upgraded; a custom style is kept', function () {
    $pdo = db();
    \ApiUsage\Stats::saveSetting($pdo, 'tts_style', \Speech\Speaker::OLD_STYLES[0]);
    assert_equal(\Speech\Speaker::DEFAULT_STYLE, \Speech\Speaker::settings()['style']);
    \ApiUsage\Stats::saveSetting($pdo, 'tts_style', 'Custom style');
    assert_equal('Custom style', \Speech\Speaker::settings()['style']);
    $pdo->exec("DELETE FROM settings WHERE key = 'tts_style'");
    assert_true(str_contains(\Speech\Speaker::DEFAULT_STYLE, 'professional'));
});
