<?php

use IMAOCustom\Helpers\Date;
use Morilog\Jalali\Jalalian;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

class DateTest extends TestCase {
    public function test_jalali_today_returns_expected_format(): void {
        $timestamp = 1704067200; // 2024-01-01 UTC
        $expected  = Jalalian::fromCarbon(Carbon::createFromTimestamp($timestamp, 'UTC'))->format('Y/m/d');
        $this->assertSame($expected, Date::jalali_today($timestamp));
    }

    public function test_is_between_checks_range(): void {
        $this->assertTrue(Date::is_between('1402/01/01', '1402/01/31', '1402/01/10'));
        $this->assertFalse(Date::is_between('1402/01/01', '1402/01/31', '1402/02/01'));
        $this->assertFalse(Date::is_between('invalid', '1402/01/31', '1402/01/10'));
    }

    public function test_is_between_inclusive_and_handles_reversed_range(): void {
        $this->assertTrue(Date::is_between('1402/01/01', '1402/01/31', '1402/01/01'));
        $this->assertTrue(Date::is_between('1402/01/01', '1402/01/31', '1402/01/31'));
        $this->assertTrue(Date::is_between('1402/01/31', '1402/01/01', '1402/01/15'));
    }

    public function test_split_handles_various_formats(): void {
        $this->assertSame(['1400','02','05'], Date::split('۱۴۰۰-۰۲-۰۵ 12:00:00'));
    }

    public function test_age_ignores_time_portion(): void {
        $this->assertSame(Date::age('1400/01/01'), Date::age('1400/01/01 08:00:00'));
    }

    public function test_age_uses_competition_date_across_birthday_boundary(): void {
        $this->assertSame(11, Date::age('1393/06/28', '1405/06/27'));
        $this->assertSame(12, Date::age('1393/06/28', '1405/06/28'));
        $this->assertNull(Date::age('1393/06/28', 'invalid'));
    }

    public function test_is_between_with_time_range(): void {
        $this->assertTrue(Date::is_between('1402/01/01 00:00:00', '1402/01/31 23:59:59', '1402/01/15 12:30:00'));
    }

    public function test_is_between_respects_time_boundaries(): void {
        $start = '1402/01/01 12:00:00';
        $end   = '1402/01/02 12:00:00';

        $this->assertTrue(Date::is_between($start, $end, '1402/01/01 12:00:00'));
        $this->assertTrue(Date::is_between($start, $end, '1402/01/02 12:00:00'));
        $this->assertFalse(Date::is_between($start, $end, '1402/01/01 11:59:59'));
        $this->assertFalse(Date::is_between($start, $end, '1402/01/02 12:00:01'));
    }
}
