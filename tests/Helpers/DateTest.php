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
}
