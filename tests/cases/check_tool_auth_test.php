<?php
// tests/cases/check_tool_auth_test.php
// Regression tests for Auth\CheckTool::verifyCredentials() - the pure
// half of the login gate for public/check/, with no session_start()
// call (see that method's own comment for why: this project's CLI test
// harness runs every test in one continuous process, which
// session_start() warns about after any prior output - the same reason
// Auth\Admin's session-dependent login path isn't unit tested directly
// either). Fixture credentials come from tests/bootstrap.php
// (testuser/testpass), not the real production ones.

declare(strict_types=1);

suite('Auth\CheckTool — verifyCredentials');

test('accepts the correct username and password', function () {
    assert_true(\Auth\CheckTool::verifyCredentials('testuser', 'testpass'));
});

test('rejects the wrong password', function () {
    assert_false(\Auth\CheckTool::verifyCredentials('testuser', 'wrongpassword'));
});

test('rejects the wrong username', function () {
    assert_false(\Auth\CheckTool::verifyCredentials('nottheuser', 'testpass'));
});

test('rejects an empty password', function () {
    assert_false(\Auth\CheckTool::verifyCredentials('testuser', ''));
});

test('username comparison is case-sensitive', function () {
    assert_false(\Auth\CheckTool::verifyCredentials('TestUser', 'testpass'));
});
