<?php
// tests/cases/faq_test.php
// Regression tests for src/Faq/Builder.php - the auto-generated FAQ list
// (public/api/chat/send.php calls capture() after a grounded answer;
// public/api/admin/faq.php is plain CRUD around the same table, exercised
// directly here rather than through the endpoint - same approach
// keyword_links_test.php takes for its own admin endpoint).

declare(strict_types=1);

// faq_entries.first_message_id/last_message_id are real foreign keys onto
// chat_messages (foreign_keys=ON - see src/bootstrap.php's db()), so every
// capture() call below needs an id that actually exists rather than an
// arbitrary literal. One seeded session/message is enough - capture()
// doesn't care which message it points to, only that the reference is
// valid.
db()->prepare('INSERT INTO chat_sessions (id, page_url) VALUES (?, ?)')
    ->execute(['faq-test-session', 'https://www.blake-uk.com/']);
db()->prepare("INSERT INTO chat_messages (id, session_id, role, content) VALUES (9001, 'faq-test-session', 'assistant', 'seed')")
    ->execute();
$mid = 9001;

suite('Faq\Builder — capture()');

test('a new question creates a new entry with hit_count 1', function () use ($mid) {
    $before = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    \Faq\Builder::capture('What are your standard delivery times?', 'Standard delivery takes 3-5 business days.', $mid);
    $after = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    assert_equal($before + 1, $after);

    $row = db()->query("SELECT hit_count FROM faq_entries WHERE question_norm = 'what are your standard delivery times'")->fetch();
    assert_equal(1, (int)$row['hit_count']);
});

test('the exact same question asked again bumps hit_count instead of duplicating', function () use ($mid) {
    \Faq\Builder::capture('How do I return a product?', 'Log in and visit the Returns Centre.', $mid);
    \Faq\Builder::capture('how do I return a product', 'Log in and visit the Returns Centre.', $mid);
    \Faq\Builder::capture('HOW DO I RETURN A PRODUCT???', 'Log in and visit the Returns Centre.', $mid);

    $rows = db()->query("SELECT hit_count FROM faq_entries WHERE question_norm = 'how do i return a product'")->fetchAll();
    assert_count(1, $rows);
    assert_equal(3, (int)$rows[0]['hit_count']);
});

test('a close rephrasing merges into the same entry via word-overlap matching', function () use ($mid) {
    \Faq\Builder::capture('What is your warranty period?', 'Most products carry a lifetime guarantee.', $mid);
    \Faq\Builder::capture('How long is the warranty period on your products?', 'Most products carry a lifetime guarantee.', $mid);

    $rows = db()->query("SELECT hit_count FROM faq_entries WHERE question_norm LIKE '%warranty period%'")->fetchAll();
    assert_count(1, $rows);
    assert_equal(2, (int)$rows[0]['hit_count']);
});

test('an unrelated question that merely shares one keyword does not merge', function () use ($mid) {
    \Faq\Builder::capture('Do you offer trade discounts?', 'Contact sales for trade pricing.', $mid);
    \Faq\Builder::capture('Do you offer next-day installation?', 'Installation slots depend on your postcode.', $mid);

    $rows = db()->query("SELECT question FROM faq_entries WHERE question_norm LIKE '%trade discounts%' OR question_norm LIKE '%next day installation%'")->fetchAll();
    assert_count(2, $rows);
});

test('capture never rewrites an existing entry\'s stored question or answer text', function () use ($mid) {
    \Faq\Builder::capture('What are your opening hours?', 'We are open Monday to Friday, 9am-5pm.', $mid);
    \Faq\Builder::capture('what are your opening hours', 'A completely different, differently-worded answer.', $mid);

    $row = db()->query("SELECT question, answer, hit_count FROM faq_entries WHERE question_norm = 'what are your opening hours'")->fetch();
    assert_equal('What are your opening hours?', $row['question']);
    assert_equal('We are open Monday to Friday, 9am-5pm.', $row['answer']);
    assert_equal(2, (int)$row['hit_count']);
});

test('a question containing an email address is not captured', function () use ($mid) {
    $before = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    \Faq\Builder::capture('Can you resend my invoice to jane.doe@example.com?', 'Sure, resending now.', $mid);
    $after = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    assert_equal($before, $after);
});

test('an answer containing a long digit run (order/tracking number) is not captured', function () use ($mid) {
    $before = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    \Faq\Builder::capture('Where is my order?', 'Your order 20260824001 was dispatched yesterday.', $mid);
    $after = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    assert_equal($before, $after);
});

test('a question containing a UK postcode is not captured', function () use ($mid) {
    $before = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    \Faq\Builder::capture('Can you deliver to S1 2JA by Friday?', 'Yes, that postcode is within our delivery area.', $mid);
    $after = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    assert_equal($before, $after);
});

test('an empty question or answer is not captured', function () use ($mid) {
    $before = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    \Faq\Builder::capture('', 'Some answer.', $mid);
    \Faq\Builder::capture('Some question?', '', $mid);
    $after = db()->query('SELECT COUNT(*) FROM faq_entries')->fetchColumn();
    assert_equal($before, $after);
});

suite('Faq\Builder — top()');

test('top() orders by hit_count descending', function () use ($mid) {
    db()->exec('DELETE FROM faq_entries');
    \Faq\Builder::capture('Popular question one?', 'Answer one.', $mid);
    \Faq\Builder::capture('popular question one', 'Answer one.', $mid);
    \Faq\Builder::capture('popular question one again', 'Answer one.', $mid);
    \Faq\Builder::capture('Rare question two?', 'Answer two.', $mid);

    $top = \Faq\Builder::top(5);
    assert_equal('Popular question one?', $top[0]['question']);
    assert_equal(3, (int)$top[0]['hit_count']);
});

test('top() respects and caps the limit', function () use ($mid) {
    db()->exec('DELETE FROM faq_entries');
    $questions = [
        'Do you sell satellite dishes?',
        'What is a CAT6 patch lead used for?',
        'Can I collect my order in person?',
        'Do your CCTV cameras work at night?',
        'Is installation included in the price?',
    ];
    foreach ($questions as $q) {
        \Faq\Builder::capture($q, 'An answer.', $mid);
    }
    assert_count(2, \Faq\Builder::top(2));
    assert_count(5, \Faq\Builder::top(100)); // caps at 20, but only 5 topically distinct entries exist
});

suite('public/api/chat/send.php integration');

test('shouldEscalate() confidence gate matches what send.php uses to decide whether to call capture()', function () {
    // send.php only calls Faq\Builder::capture() when !$escalate. This is a
    // guard against that condition silently drifting apart from
    // Responder::confidence()/shouldEscalate() in a future change -
    // exercising send.php itself would need a real HTTP/Gemini round trip,
    // which is out of scope for this fast suite (see tests/eval/ for that).
    assert_false(\Chat\Responder::shouldEscalate(0.75));
    assert_true(\Chat\Responder::shouldEscalate(0.3));
});
