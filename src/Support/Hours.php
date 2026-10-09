<?php
// src/Support/Hours.php
// Blake UK support opening hours (UK time):
//   Monday - Thursday 08:00 - 16:30, Friday 08:00 - 16:00, closed weekends and all bank holidays
// (England and Wales, see BankHolidays; plus any company closure dates saved in the settings).
// Used to decide whether a chat handed to staff waits for someone or goes
// straight to an AI-raised ticket, and for the wording customers see.

declare(strict_types=1);

namespace Support;

class Hours
{
    public const TZ = 'Europe/London';

    // ISO weekday (1 = Monday) => [open 'HH:MM', close 'HH:MM'] or null (closed).
    public const WEEK = [
        1 => ['08:00', '16:30'],
        2 => ['08:00', '16:30'],
        3 => ['08:00', '16:30'],
        4 => ['08:00', '16:30'],
        5 => ['08:00', '16:00'],
        6 => null,
        7 => null,
    ];

    public const SUMMARY = 'Monday to Thursday 8:00am to 4:30pm, Friday 8:00am to 4:00pm, closed Saturday, Sunday and bank holidays';

    private static function at(?int $ts): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('@' . ($ts ?? time())))->setTimezone(new \DateTimeZone(self::TZ));
    }

    // Tests pin opening state with this; null = real clock.
    public static ?bool $override = null;

    public static function isOpen(?int $ts = null): bool
    {
        if (self::$override !== null) return self::$override;
        $d   = self::at($ts);
        $day = self::WEEK[(int)$d->format('N')];
        if ($day === null || BankHolidays::isClosedDay($d->format('Y-m-d'))) return false;
        $hm = $d->format('H:i');
        return $hm >= $day[0] && $hm < $day[1];
    }

    // Human wording for when the team is next available, e.g. "today at
    // 8:00am", "tomorrow at 8:00am", "on Monday at 8:00am".
    public static function nextOpening(?int $ts = null): string
    {
        $now = self::at($ts);
        for ($i = 0; $i <= 21; $i++) {      // far enough to get past Christmas and Easter
            $day = $now->modify("+{$i} day");
            $h   = self::WEEK[(int)$day->format('N')];
            if ($h === null || BankHolidays::isClosedDay($day->format('Y-m-d'))) continue;
            [$oh, $om] = array_map('intval', explode(':', $h[0]));
            $open = $day->setTime($oh, $om);
            if ($open <= $now) continue;
            $time = ltrim($open->format('g:ia'), '0');
            $time = str_replace(':00', ':00', $time);
            if ($i === 0) return "today at {$time}";
            if ($i === 1) return "tomorrow at {$time}";
            return 'on ' . $open->format('l') . " at {$time}";
        }
        return 'on our next working day';
    }
}
