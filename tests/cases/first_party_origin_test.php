<?php
// tests/cases/first_party_origin_test.php

suite('First-party origins');

test('blakegroup.uk site origins are first-party', function () {
    assert_true(is_first_party_origin('https://blakegroup.uk'));
    assert_true(is_first_party_origin('https://www.blakegroup.uk'));
    assert_true(is_first_party_origin('https://chat.blakegroup.uk'));
});

test('empty, http and foreign origins are not first-party', function () {
    assert_false(is_first_party_origin(''));
    assert_false(is_first_party_origin('http://blakegroup.uk'));
    assert_false(is_first_party_origin('https://evil.example'));
    assert_false(is_first_party_origin('https://blakegroup.uk.evil.example'));
});
