<?php
// Opening hours and bank holidays (England and Wales).

declare(strict_types=1);

use Support\BankHolidays;
use Support\Hours;

function bh_fixture(): array
{
    $events = json_decode(file_get_contents(__DIR__ . '/../fixtures/gov-uk-bank-holidays.json'), true)['england-and-wales']['events'];
    $by = [];
    foreach ($events as $e) $by[(int)substr($e['date'], 0, 4)][] = $e['date'];
    return array_map(function ($d) { sort($d); return $d; }, $by);
}

function bh_reset(): void
{
    foreach (['bank_holidays_feed', 'bank_holidays_fetched', 'bank_holidays_tried', 'support_closed_dates'] as $k) db()->prepare('DELETE FROM settings WHERE key = ?')->execute([$k]);
    BankHolidays::forgetCache();
    Hours::$override = null;
}

function bh_ts(string $local): int
{
    return (new \DateTimeImmutable($local, new \DateTimeZone('Europe/London')))->getTimestamp();
}

suite('Bank holidays: the standing rules');

test('the rules match the government\'s list for every ordinary year, and differ only in the three years with one-off changes', function () {
    $oneOffs = [2020 => [['2020-05-08'], ['2020-05-04']], 2022 => [['2022-06-02', '2022-06-03', '2022-09-19'], ['2022-05-30']], 2023 => [['2023-05-08'], []]];
    foreach (bh_fixture() as $year => $official) {
        $rules = BankHolidays::rules($year);
        if (!isset($oneOffs[$year])) { assert_equal($official, $rules, "year $year"); continue; }
        assert_equal($oneOffs[$year][0], array_values(array_diff($official, $rules)), "$year: days only the government has");
        assert_equal($oneOffs[$year][1], array_values(array_diff($rules, $official)), "$year: days only the rules have");
    }
});

test('the next few years, checked by hand against the calendar', function () {
    assert_equal(['2026-01-01', '2026-04-03', '2026-04-06', '2026-05-04', '2026-05-25', '2026-08-31', '2026-12-25', '2026-12-28'], BankHolidays::rules(2026));
    assert_equal(['2027-01-01', '2027-03-26', '2027-03-29', '2027-05-03', '2027-05-31', '2027-08-30', '2027-12-27', '2027-12-28'], BankHolidays::rules(2027));
    assert_equal(['2028-01-03', '2028-04-14', '2028-04-17', '2028-05-01', '2028-05-29', '2028-08-28', '2028-12-25', '2028-12-26'], BankHolidays::rules(2028));
    assert_equal('2029-04-01', BankHolidays::easterSunday(2029)->format('Y-m-d'));
    assert_equal('2038-04-25', BankHolidays::easterSunday(2038)->format('Y-m-d'), 'the latest Easter possible');
    foreach ([2029, 2030, 2031, 2040, 2100] as $y) assert_count(8, BankHolidays::rules($y));
});

test('Christmas and Boxing Day move correctly whichever day of the week they fall on', function () {
    $xmas = fn(int $y) => array_slice(BankHolidays::rules($y), -2);
    assert_equal(['2022-12-26', '2022-12-27'], $xmas(2022), 'Christmas on a Sunday');
    assert_equal(['2021-12-27', '2021-12-28'], $xmas(2021), 'Christmas on a Saturday');
    assert_equal(['2020-12-25', '2020-12-28'], $xmas(2020), 'Christmas on a Friday');
    assert_equal(['2024-12-25', '2024-12-26'], $xmas(2024), 'Christmas on a Wednesday');
});

suite('Bank holidays: the government\'s list');

test('the list is read, replaces the rules for the years it covers, and the rules cover the rest', function () {
    bh_reset();
    assert_true(BankHolidays::isClosedDay('2026-04-06'), 'Easter Monday, from the rules');
    assert_true(!BankHolidays::isClosedDay('2026-04-07'));
    assert_true(!BankHolidays::isClosedDay('2022-06-02'), 'the rules do not know the Jubilee holiday');
    $json = file_get_contents(__DIR__ . '/../fixtures/gov-uk-bank-holidays.json');
    assert_true(BankHolidays::refreshIfStale(fn($url) => $json, 1_000_000));
    assert_true(BankHolidays::isClosedDay('2022-06-02'), 'the government list knows it');
    assert_true(BankHolidays::isClosedDay('2023-05-08'), 'and the Coronation');
    assert_true(!BankHolidays::isClosedDay('2022-05-30'), 'the Spring holiday was moved, so 30 May 2022 was an ordinary day');
    assert_true(BankHolidays::isClosedDay('2035-12-25'), 'a year the list does not reach falls back to the rules');
    bh_reset();
});

test('it is refreshed about weekly, retried after a failure, and a bad answer never replaces a good list', function () {
    bh_reset();
    $json = file_get_contents(__DIR__ . '/../fixtures/gov-uk-bank-holidays.json'); $calls = 0;
    $good = function ($u) use ($json, &$calls) { $calls++; return $json; };
    assert_true(BankHolidays::refreshIfStale($good, 1_000_000)); assert_equal(1, $calls);
    assert_true(!BankHolidays::refreshIfStale($good, 1_000_000 + 6 * 86400), 'still fresh after six days'); assert_equal(1, $calls);
    // after a week it tries again, and a failure keeps the old list
    $fail = function ($u) use (&$calls) { $calls++; return null; };
    assert_true(!BankHolidays::refreshIfStale($fail, 1_000_000 + 8 * 86400)); assert_equal(2, $calls);
    assert_true(BankHolidays::isClosedDay('2023-05-08'), 'the old list is still in use');
    assert_true(!BankHolidays::refreshIfStale($good, 1_000_000 + 8 * 86400 + 3600), 'not retried within six hours'); assert_equal(2, $calls);
    assert_true(!BankHolidays::refreshIfStale(fn($u) => '<html>Maintenance</html>', 1_000_000 + 9 * 86400));
    assert_true(!BankHolidays::refreshIfStale(fn($u) => '{"england-and-wales":{"events":[]}}', 1_000_000 + 10 * 86400));
    assert_true(BankHolidays::isClosedDay('2023-05-08'), 'junk never replaces a good list');
    assert_true(BankHolidays::refreshIfStale($good, 1_000_000 + 11 * 86400), 'and the next good answer is taken');
    bh_reset();
});

test('parseFeed accepts the real file and refuses anything else', function () {
    $dates = BankHolidays::parseFeed(file_get_contents(__DIR__ . '/../fixtures/gov-uk-bank-holidays.json'));
    assert_true($dates !== null && in_array('2026-12-28', $dates, true) && !in_array('2026-12-26', $dates, true));
    assert_equal(array_values(array_unique($dates)), $dates);
    foreach (['', 'null', '[]', '{"scotland":{"events":[]}}', '{"england-and-wales":{"events":"x"}}'] as $bad) assert_null(BankHolidays::parseFeed($bad), "refused: $bad");
});

test('company closure days can be added, and bad entries are ignored', function () {
    bh_reset();
    \Mail\Smtp::saveSetting('support_closed_dates', "2026-12-29, 2026-12-30;nonsense 2026-13-45x 2026-12-31");
    assert_equal(['2026-12-29', '2026-12-30', '2026-12-31'], BankHolidays::closedDates());
    assert_true(BankHolidays::isClosedDay('2026-12-30')); assert_true(!BankHolidays::isClosedDay('2026-12-23'));
    bh_reset();
});

suite('Opening hours with bank holidays');

test('open Monday to Thursday 8:00 to 4:30, Friday 8:00 to 4:00, to the minute', function () {
    bh_reset();
    foreach ([['2026-10-05 07:59', false], ['2026-10-05 08:00', true], ['2026-10-08 16:29', true], ['2026-10-08 16:30', false], ['2026-10-09 15:59', true], ['2026-10-09 16:00', false], ['2026-10-10 11:00', false], ['2026-10-11 11:00', false]] as [$when, $open]) {
        assert_equal($open, Hours::isOpen(bh_ts($when)), $when);
    }
});

test('closed on bank holidays, open the day after', function () {
    bh_reset();
    foreach (['2026-04-03 10:00', '2026-04-06 10:00', '2026-05-04 10:00', '2026-05-25 10:00', '2026-08-31 10:00', '2026-12-25 10:00', '2026-12-28 10:00', '2027-01-01 10:00'] as $d) assert_true(!Hours::isOpen(bh_ts($d)), "closed: $d");
    foreach (['2026-04-07 10:00', '2026-04-02 10:00', '2026-05-05 10:00', '2026-09-01 10:00', '2026-12-29 10:00'] as $d) assert_true(Hours::isOpen(bh_ts($d)), "open: $d");
});

test('"next opening" skips weekends and bank holidays', function () {
    bh_reset();
    assert_equal('on Tuesday at 8:00am', Hours::nextOpening(bh_ts('2026-04-03 17:00')), 'Good Friday evening: Saturday, Sunday and Easter Monday are all closed');
    assert_equal('tomorrow at 8:00am', Hours::nextOpening(bh_ts('2026-04-06 09:00')), 'on Easter Monday itself, the next day is open');
    assert_equal('tomorrow at 8:00am', Hours::nextOpening(bh_ts('2026-04-07 17:00')));
    assert_equal('on Tuesday at 8:00am', Hours::nextOpening(bh_ts('2026-12-24 17:00')), 'Christmas Eve evening: Christmas Day, the weekend and the substitute Boxing Day, then Tuesday');
    assert_equal('today at 8:00am', Hours::nextOpening(bh_ts('2026-10-06 06:00')));
    \Mail\Smtp::saveSetting('support_closed_dates', '2026-12-29');
    assert_equal('on Wednesday at 8:00am', Hours::nextOpening(bh_ts('2026-12-24 17:00')), 'a company closure day is skipped too');
    bh_reset();
});

test('the wording customers see says bank holidays are closed', function () {
    assert_equal('Monday to Thursday 8:00am to 4:30pm, Friday 8:00am to 4:00pm, closed Saturday, Sunday and bank holidays', Hours::SUMMARY);
});

test('(restore the opening-hours override other tests rely on)', function () { Hours::$override = true; });
