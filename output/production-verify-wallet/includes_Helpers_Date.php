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
     * Convert a WordPress/MySQL Gregorian date into a sortable Jalali value.
     */
    public static function to_jalali(string $date, string $format = 'Y/m/d H:i'): string {
        $date = trim($date);
        if ($date === '' || $date === '0000-00-00 00:00:00') {
            return '';
        }

        try {
            $tz = function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC');
            $gregorian = new \DateTimeImmutable($date, $tz);
            return Jalalian::fromDateTime($gregorian)->format($format);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Check if current date is within the given Jalali range.
     *
     * Accepts dates formatted as "Y/m/d" or "Y/m/d H:i:s".
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
     * Calculate age in years from a Jalali birth date.
     */
    public static function age(string $birth): ?int {
        $birthDate = self::parse($birth);
        if (! $birthDate) {
            return null;
        }
        return $birthDate->toCarbon()->age;
    }

    /**
     * Split a date string into year, month and day parts after normalization.
     *
     * Accepts various separators or Persian digits and ignores time portions.
     * Returns an array with three elements: [year, month, day].
     */
    public static function split(string $date): array {
        $date   = self::normalize($date);
        $date   = explode(' ', $date)[0];
        $parts  = explode('/', $date);
        return array_pad($parts, 3, '');
    }

    /**
     * Parse a Jalali date string to a Jalalian instance.
     *
     * Supports "Y/m/d" and "Y/m/d H:i:s" formats.
     */
    private static function parse(string $date): ?Jalalian {
        $date = self::normalize($date);

        try {
            if (strpos($date, ' ') !== false) {
                return Jalalian::fromFormat('Y/m/d H:i:s', $date);
            }

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
