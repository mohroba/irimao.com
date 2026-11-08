<?php

namespace IMAOCustom\Services;

use Morilog\Jalali\Jalalian;
use RuntimeException;

/** Calendar-month tuition; amounts entered by administrators are always tomans. */
final class MonthlyClassBilling {
    public static function normalize_digits( string $value ): string {
        return strtr( trim( $value ), array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) );
    }

    public static function amount( string $value ): string {
        $value = str_replace( [ ',', '٬' ], '', self::normalize_digits( $value ) );
        if ( ! preg_match( '/^\d{1,12}$/D', $value ) || (float) $value <= 0 ) return '';
        return (string) (int) $value;
    }

    public static function time( string $value ): string {
        $value = self::normalize_digits( $value );
        return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value ) ? $value : '';
    }

    public static function store_amount( float $tomans, string $currency ): float {
        if ( in_array( $currency, [ 'IRT', 'IRToman' ], true ) ) return $tomans;
        if ( $currency === 'IRR' ) return $tomans * 10;
        if ( $currency === 'IRHT' ) return $tomans / 1000;
        throw new RuntimeException( 'واحد پول فروشگاه برای شهریهٔ تومانی پشتیبانی نمی‌شود.' );
    }

    public static function toman_amount( float $amount, string $currency ): float {
        return $amount / self::store_amount( 1, $currency );
    }

    public static function class_fee( int $id ): float {
        $fee = (float) get_post_meta( $id, '_class_fee', true );
        return get_post_meta( $id, '_class_fee_unit', true ) === 'toman' ? $fee : self::toman_amount( $fee, get_woocommerce_currency() );
    }

    public static function display_fee( int $id ): string {
        return number_format( self::class_fee( $id ), 0, '.', ',' ) . ' تومان';
    }

    /** First payment covers today inclusively through month end; renewals cover a full month. */
    public static function period( string $today, float $fee, bool $first, string $last_due = '' ): array {
        if ( ! is_finite( $fee ) || $fee <= 0 ) throw new RuntimeException( 'شهریهٔ کلاس معتبر نیست.' );
        $timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'Asia/Tehran' );
        $now = new \DateTimeImmutable( $today, $timezone );
        if ( ! $first && $last_due !== '' && $last_due > $now->format( 'Y-m-d' ) ) $now = new \DateTimeImmutable( $last_due, $timezone );
        $date = Jalalian::fromDateTime( $now );
        $start_day = $first ? $date->getDay() : 1;
        $start = new Jalalian( $date->getYear(), $date->getMonth(), $start_day, 0, 0, 0, $timezone );
        $end = new Jalalian( $date->getYear(), $date->getMonth(), $date->getMonthDays(), 0, 0, 0, $timezone );
        $next = $end->toCarbon()->addDay()->format( 'Y-m-d' );
        $days = $first ? $date->getMonthDays() - $date->getDay() + 1 : $date->getMonthDays();
        return [ 'version' => 2, 'period' => $date->format( 'Y-m' ), 'start' => $start->toCarbon()->format( 'Y-m-d' ),
            'end' => $end->toCarbon()->format( 'Y-m-d' ), 'due_date' => $next, 'days' => $days, 'month_days' => $date->getMonthDays(),
            'first' => $first, 'monthly_fee_toman' => $fee, 'amount_toman' => max( 1.0, round( $fee * $days / $date->getMonthDays(), 0 ) ) ];
    }
}
