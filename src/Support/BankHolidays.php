<?php
// src/Support/BankHolidays.php
// Bank holidays for England and Wales (the office is in Sheffield). Support is closed on all of them.
//
// Two sources, in this order:
//   1. the government's own list (https://www.gov.uk/bank-holidays.json), refreshed weekly by the support cron job and
//      kept in the settings table. It is the authority, and it includes one-off days such as a coronation;
//   2. the standing rules (below), used for any year the list does not cover, or if it has never been fetched.
// On top of either, "support_closed_dates" in the settings table (comma-separated YYYY-MM-DD) adds days the company is
// shut for its own reasons, such as the days between Christmas and New Year.

declare(strict_types=1);

namespace Support;

class BankHolidays
{
    public const FEED_URL = 'https://www.gov.uk/bank-holidays.json';
    private const REFRESH_AFTER = 7 * 86400;   // a good list is refreshed weekly
    private const RETRY_AFTER   = 6 * 3600;    // a failed attempt is retried after six hours

    private static ?array $feedCache = null;   // [year => [dates]] or [] when there is no list

    // ---- the standing rules ------------------------------------------------------------------------------------

    public static function easterSunday(int $y): \DateTimeImmutable
    {
        $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4; $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30; $i = intdiv($c, 4); $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $n = $h + $l - 7 * $m + 114;
        return new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, intdiv($n, 31), $n % 31 + 1));
    }

    // The days the rules give for a year, as YYYY-MM-DD. One-off government changes are not known to the rules.
    public static function rules(int $y): array
    {
        $d = fn(string $s) => new \DateTimeImmutable("{$y}-{$s}");
        $out = [];
        $ny = $d('01-01'); $dow = (int)$ny->format('N');
        $out[] = ($dow === 6 ? $ny->modify('+2 days') : ($dow === 7 ? $ny->modify('+1 day') : $ny))->format('Y-m-d');
        $easter = self::easterSunday($y);
        $out[] = $easter->modify('-2 days')->format('Y-m-d');   // Good Friday
        $out[] = $easter->modify('+1 day')->format('Y-m-d');    // Easter Monday
        $out[] = $d('05-01')->modify($d('05-01')->format('N') === '1' ? 'monday this week' : 'next monday')->format('Y-m-d');   // Early May: first Monday
        $out[] = $d('05-31')->modify($d('05-31')->format('N') === '1' ? 'monday this week' : 'last monday')->format('Y-m-d');   // Spring: last Monday of May
        $out[] = $d('08-31')->modify($d('08-31')->format('N') === '1' ? 'monday this week' : 'last monday')->format('Y-m-d');   // Summer: last Monday of August
        // Christmas Day and Boxing Day, with the substitute days when they fall on a weekend
        switch ((int)$d('12-25')->format('N')) {
            case 6:  $out[] = "{$y}-12-27"; $out[] = "{$y}-12-28"; break;   // Christmas Saturday, Boxing Day Sunday
            case 7:  $out[] = "{$y}-12-26"; $out[] = "{$y}-12-27"; break;   // Christmas Sunday: Boxing Day Monday, Christmas Tuesday
            case 5:  $out[] = "{$y}-12-25"; $out[] = "{$y}-12-28"; break;   // Boxing Day Saturday
            default: $out[] = "{$y}-12-25"; $out[] = "{$y}-12-26";
        }
        sort($out);
        return $out;
    }

    // ---- the government's list ---------------------------------------------------------------------------------

    // Reads the saved list into [year => [dates]]; an empty array means there is none.
    private static function feed(): array
    {
        if (self::$feedCache !== null) return self::$feedCache;
        $q = db()->prepare("SELECT value FROM settings WHERE key = 'bank_holidays_feed'");
        $q->execute();
        $j = json_decode((string)$q->fetchColumn(), true);
        $by = [];
        foreach (is_array($j['dates'] ?? null) ? $j['dates'] : [] as $date) {
            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $by[(int)substr($date, 0, 4)][] = $date;
        }
        return self::$feedCache = $by;
    }

    public static function forgetCache(): void { self::$feedCache = null; }

    // Turns the government's JSON into a list of dates for England and Wales, or null if it is not what we expect.
    public static function parseFeed(string $json): ?array
    {
        $d = json_decode($json, true);
        $events = $d['england-and-wales']['events'] ?? null;
        if (!is_array($events) || count($events) < 20) return null;
        $dates = [];
        foreach ($events as $e) {
            if (is_array($e) && is_string($e['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e['date'])) $dates[] = $e['date'];
        }
        sort($dates);
        return count($dates) >= 20 ? array_values(array_unique($dates)) : null;
    }

    // Called by the support cron job every minute; it only does anything about once a week.
    public static function refreshIfStale(?callable $fetch = null, ?int $now = null): bool
    {
        $now ??= time();
        $get = static function (string $k): int { $q = db()->prepare('SELECT value FROM settings WHERE key = ?'); $q->execute([$k]); return (int)$q->fetchColumn(); };
        $fetched = $get('bank_holidays_fetched'); $tried = $get('bank_holidays_tried');
        if ($fetched && $now - $fetched < self::REFRESH_AFTER) return false;
        if ($tried && $now - $tried < self::RETRY_AFTER) return false;
        \Mail\Smtp::saveSetting('bank_holidays_tried', (string)$now);
        $body = $fetch ? $fetch(self::FEED_URL) : self::download(self::FEED_URL);
        $dates = $body === null ? null : self::parseFeed($body);
        if ($dates === null) return false;                       // keep whatever we had
        \Mail\Smtp::saveSetting('bank_holidays_feed', json_encode(['dates' => $dates]));
        \Mail\Smtp::saveSetting('bank_holidays_fetched', (string)$now);
        self::forgetCache();
        return true;
    }

    private static function download(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS]);
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return ($r !== false && $code === 200) ? (string)$r : null;
    }

    // ---- the question ----------------------------------------------------------------------------------------

    public static function closedDates(): array
    {
        $q = db()->prepare("SELECT value FROM settings WHERE key = 'support_closed_dates'");
        $q->execute();
        return array_values(array_filter(preg_split('/[\s,;]+/', (string)$q->fetchColumn(), -1, PREG_SPLIT_NO_EMPTY) ?: [], fn($s) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)));
    }

    // Is $ymd (YYYY-MM-DD, UK date) a bank holiday, or a day the company has marked as closed?
    public static function isClosedDay(string $ymd): bool
    {
        $y = (int)substr($ymd, 0, 4);
        $feed = self::feed();
        $days = isset($feed[$y]) ? $feed[$y] : self::rules($y);   // the government's list wins for any year it covers
        return in_array($ymd, $days, true) || in_array($ymd, self::closedDates(), true);
    }
}
