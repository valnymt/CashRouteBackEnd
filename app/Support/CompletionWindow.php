<?php

namespace App\Support;

/**
 * Maps the "Complete within" dropdown labels used by the portal
 * (src/lib/format.ts COMPLETION_WINDOWS) to the enum values stored
 * in cash_requests.complete_within. Also computes the deadline for
 * on-time / late reporting.
 */
class CompletionWindow
{
    /** Label (what the portal sends/shows) → enum (what the DB stores). */
    public const MAP = [
        'Within 1 hour' => '1h',
        'Within 3 hours' => '3h',
        'Within 5 hours' => '5h',
        'Within 10 hours' => '10h',
        'Within 12 hours' => '12h',
        'Within 1 day' => '1d',
        'Within 2 days' => '2d',
    ];

    /** All allowed labels — used by validation. */
    public static function labels(): array
    {
        return array_keys(self::MAP);
    }

    /** 'Within 3 hours' → '3h' */
    public static function toEnum(string $label): ?string
    {
        return self::MAP[$label] ?? null;
    }

    /** '3h' → 'Within 3 hours' */
    public static function toLabel(string $enum): string
    {
        return array_search($enum, self::MAP, true) ?: $enum;
    }

    /** Deadline for on-time vs late: requested_date + window. */
    public static function dueAt(string $date, string $enum): \DateTimeImmutable
    {
        $hours = match ($enum) {
            '1h' => 1, '3h' => 3, '5h' => 5, '10h' => 10, '12h' => 12,
            '1d' => 24, '2d' => 48, default => 24,
        };

        return (new \DateTimeImmutable($date))->modify("+{$hours} hours");
    }
}
