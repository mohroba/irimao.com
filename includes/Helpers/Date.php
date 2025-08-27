<?php

namespace IMAOCustom\Helpers;

use Carbon\Carbon;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

class Date {
    /**
     * Today's Jalali date formatted as yyyy/MM/dd.
     */
    public static function jalali_today(?int $timestamp = null): string {
        $tz    = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        $carbon = $timestamp !== null
            ? Carbon::createFromTimestamp($timestamp, new \DateTimeZone($tz))
            : Carbon::now(new \DateTimeZone($tz));
        return Jalalian::fromCarbon($carbon)->format('Y/m/d');
    }

    /**
     * Check if current date is within the given Jalali range.
     */
    public static function is_between(string $start, string $end, ?string $current = null): bool {
        $startDate   = self::parse($start);
        $endDate     = self::parse($end);
        $currentDate = $current ? self::parse($current) : self::now();

        if (! $startDate || ! $endDate || ! $currentDate) {
            return false;
        }

        $startTs   = $startDate->getTimestamp();
        $endTs     = $endDate->getTimestamp();
        $currentTs = $currentDate->getTimestamp();

        if ($startTs > $endTs) {
            [$startTs, $endTs] = [$endTs, $startTs];
        }

        return $currentTs >= $startTs && $currentTs <= $endTs;
    }

    /**
     * Parse a Jalali date string (yyyy/MM/dd) to Jalalian instance.
     */
    private static function parse(string $date): ?Jalalian {
        $date = self::normalize($date);
        try {
            return Jalalian::fromFormat('Y/m/d', $date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convert Persian digits and separators to standard format.
     */
    private static function normalize(string $date): string {
        return str_replace('-', '/', CalendarUtils::convertNumbers(trim($date), true));
    }

    private static function now(): Jalalian {
        $tz = function_exists('wp_timezone_string') ? wp_timezone_string() : 'UTC';
        return Jalalian::now(new \DateTimeZone($tz));
    }
}
