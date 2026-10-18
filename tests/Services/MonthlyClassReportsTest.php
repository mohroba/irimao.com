<?php

use IMAOCustom\Services\Admin\MonthlyClassReports;
use PHPUnit\Framework\TestCase;

final class MonthlyClassReportsTest extends TestCase {
    /** @dataProvider dates */
    public function test_filter_dates_are_converted_without_rollover( string $input, string $expected ): void {
        $method = new ReflectionMethod( MonthlyClassReports::class, 'parse_date' );
        $method->setAccessible( true );
        $this->assertSame( $expected, $method->invoke( new MonthlyClassReports(), $input ) );
    }

    public function dates(): array {
        return [
            [ '۱۴۰۵/۰۱/۰۱', '2026-03-21' ],
            [ '1405-07-26', '2026-10-18' ],
            [ '2026/10/18', '2026-10-18' ],
            [ '1399/12/30', '2021-03-20' ],
        ];
    }

    /** @dataProvider invalid_dates */
    public function test_invalid_dates_are_rejected( string $input ): void {
        $method = new ReflectionMethod( MonthlyClassReports::class, 'parse_date' );
        $method->setAccessible( true );
        $this->expectException( InvalidArgumentException::class );
        $method->invoke( new MonthlyClassReports(), $input );
    }

    public function invalid_dates(): array {
        return [ [ '1400/12/30' ], [ '1405/07/31' ], [ '2026/02/30' ], [ '1405/13/01' ], [ 'x' ] ];
    }
}
