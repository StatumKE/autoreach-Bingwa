<?php

namespace App\Support;

use Carbon\Carbon;
use DateTimeInterface;

final class AppTimezone
{
    public static function name(): string
    {
        return (string) config('app.timezone', 'Africa/Nairobi');
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::name());
    }

    public static function format(DateTimeInterface|Carbon|string|null $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        if (is_string($value)) {
            $value = Carbon::parse($value);
        } elseif (! $value instanceof Carbon) {
            $value = Carbon::instance($value);
        }

        return $value
            ->timezone(self::name())
            ->format($format);
    }
}
