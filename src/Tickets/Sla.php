<?php
// src/Tickets/Sla.php

declare(strict_types=1);

namespace Tickets;

class Sla
{
    // Response-time windows in seconds, by priority. These are starting
    // defaults (2h/8h/24h/72h) rather than anything specified elsewhere -
    // easiest single place to retune once real response-time targets exist.
    public const WINDOWS = [
        'urgent' => 2 * 3600,
        'high'   => 8 * 3600,
        'medium' => 24 * 3600,
        'low'    => 72 * 3600,
    ];

    public static function deadline(string $priority, int $createdAt): int
    {
        return $createdAt + (self::WINDOWS[$priority] ?? self::WINDOWS['medium']);
    }
}
