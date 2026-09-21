<?php
// tests/cases/downloads_test.php - files offered to customers as downloads.
declare(strict_types=1);

suite('Knowledge\Downloads — customer download offers');

test('only files marked as downloads are offered, on keyword or content match', function () {
    $pdo = db();
    $path = tempnam(sys_get_temp_dir(), 'dl');
    file_put_contents($path, '%PDF-1.4 test');
    $pdo->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES ('Blake_UK_Application_for_Trading_Account_and_Terms_and_Conditions.pdf','application/pdf',?,'indexed')")->execute([$path]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES ('Internal_price_list.pdf','application/pdf',?,'indexed')")->execute([$path]);
    $secret = (int)$pdo->lastInsertId();

    assert_equal([], \Knowledge\Downloads::forQuestion('how do I apply for a trade account'));   // not marked yet
    $r = \Knowledge\Downloads::set($id, true, 'Trade Account Application Form', 'trade account, account application, credit account');
    assert_true($r['ok'] && preg_match('/^[a-f0-9]{32}$/', $r['token']) === 1);

    $d = \Knowledge\Downloads::forQuestion('How do I apply for a Trade Account?');
    assert_equal(1, count($d));
    assert_equal('Trade Account Application Form', $d[0]['title']);
    assert_equal('PDF', $d[0]['type']);
    assert_str_contains('/api/chat/file.php?t=' . $r['token'], $d[0]['url']);

    // Content match: an answer drawn from the file offers it too.
    assert_equal(1, count(\Knowledge\Downloads::forQuestion('what are your payment terms', [['source_type' => 'file', 'source_id' => $id]])));
    // Unmarked files never appear, even on a content match.
    assert_equal(0, count(array_filter(\Knowledge\Downloads::forQuestion('price list', [['source_type' => 'file', 'source_id' => $secret]]), fn($x) => $x['id'] === $secret)));
    assert_equal([], \Knowledge\Downloads::forQuestion('which aerial do I need'));

    \Knowledge\Downloads::set($id, false, 'Trade Account Application Form', 'trade account');
    assert_equal([], \Knowledge\Downloads::forQuestion('trade account please'));
    $pdo->exec("DELETE FROM knowledge_files WHERE id IN ($id, $secret)");
    @unlink($path);
});

test('buildContext passes downloads through and the prompt tells Max to offer them', function () {
    $pdo = db();
    $path = tempnam(sys_get_temp_dir(), 'dl');
    file_put_contents($path, 'x');
    $pdo->prepare("INSERT INTO knowledge_files (filename, mime_type, stored_path, status) VALUES ('Form.pdf','application/pdf',?,'indexed')")->execute([$path]);
    $id = (int)$pdo->lastInsertId();
    \Knowledge\Downloads::set($id, true, 'Trade Account Application Form', 'trade account');
    \Knowledge\Embeddings::resetCaches();
    $ctx = \Chat\Responder::buildContext('can I open a trade account', null, '');
    assert_equal('Trade Account Application Form', $ctx['downloads'][0]['title'] ?? null);
    assert_str_contains('DOWNLOADS available to the customer', \Chat\Responder::buildPrompt($ctx, null, null));
    $pdo->exec("DELETE FROM knowledge_files WHERE id = $id");
    @unlink($path);
});
