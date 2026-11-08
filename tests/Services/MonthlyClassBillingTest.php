<?php

use IMAOCustom\Services\MonthlyClassBilling;
use Morilog\Jalali\Jalalian;
use PHPUnit\Framework\TestCase;

final class MonthlyClassBillingTest extends TestCase {
    public function test_first_month_includes_today_and_handles_leap_esfand(): void {
        foreach ( [ [ 1405, 1, 16, 31, 16 ], [ 1405, 8, 18, 30, 13 ], [ 1399, 12, 30, 30, 1 ], [ 1402, 12, 29, 29, 1 ] ] as $parts ) {
            [ $year, $month, $day, $days, $remaining ] = $parts;
            $date = new Jalalian( $year, $month, $day, 0, 0, 0, new DateTimeZone( 'Asia/Tehran' ) );
            $quote = MonthlyClassBilling::period( $date->toCarbon()->format( 'Y-m-d' ), 310000, true );
            $this->assertSame( $remaining, $quote['days'] );
            $this->assertSame( round( 310000 * $remaining / $days, 0 ), $quote['amount_toman'] );
            $due = Jalalian::fromDateTime( new DateTimeImmutable( $quote['due_date'], new DateTimeZone( 'Asia/Tehran' ) ) );
            $this->assertSame( 1, $due->getDay() );
            $this->assertSame( $month === 12 ? 1 : $month + 1, $due->getMonth() );
        }
    }

    public function test_renewal_is_full_month_and_prepaid_month_is_not_charged_twice(): void {
        $today = ( new Jalalian( 1405, 8, 18 ) )->toCarbon()->format( 'Y-m-d' );
        $due = ( new Jalalian( 1405, 9, 1 ) )->toCarbon()->format( 'Y-m-d' );
        $quote = MonthlyClassBilling::period( $today, 1000000, false, $due );
        $this->assertSame( '1405-09', $quote['period'] );
        $this->assertSame( 1000000.0, $quote['amount_toman'] );
        $this->assertSame( 30, $quote['days'] );
    }

    public function test_money_and_times_accept_persian_input_without_losing_toman_unit(): void {
        $this->assertSame( '1000000', MonthlyClassBilling::amount( '۱٬۰۰۰٬۰۰۰' ) );
        $this->assertSame( '1000000', MonthlyClassBilling::amount( '1,000,000' ) );
        $this->assertSame( '', MonthlyClassBilling::amount( '-100' ) );
        $this->assertSame( '', MonthlyClassBilling::amount( '1e6' ) );
        $this->assertSame( '10:30', MonthlyClassBilling::time( '۱۰:۳۰' ) );
        $this->assertSame( '', MonthlyClassBilling::time( '25:00' ) );
        foreach ( [ 'IRT' => 1000000.0, 'IRR' => 10000000.0, 'IRHT' => 1000.0 ] as $currency => $amount ) {
            $this->assertSame( $amount, MonthlyClassBilling::store_amount( 1000000, $currency ) );
            $this->assertSame( 1000000.0, MonthlyClassBilling::toman_amount( $amount, $currency ) );
        }
    }
}
