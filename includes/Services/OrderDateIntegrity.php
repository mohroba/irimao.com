<?php

namespace IMAOCustom\Services;

use Morilog\Jalali\CalendarUtils;

/** Rejects accidental Gregorian-as-Jalali dates without guessing a replacement. */
final class OrderDateIntegrity {
    public function register(): void {
        add_action( 'woocommerce_before_order_object_save', [ $this, 'guard' ], PHP_INT_MAX );
    }

    /** A repair is safe only when the exact wrong conversion, including time, is reproduced. */
    public static function verified_reference( string $current, string $reference, ?int $year = null ): ?string {
        $year = $year ?? (int) gmdate( 'Y' );
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2}) (\d{2}:\d{2}:\d{2})$/D', $reference, $parts )
            || ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $current ) ) {
            return null;
        }
        [ $y, $m, $d ] = array_map( 'intval', array_slice( $parts, 1, 3 ) );
        if ( $y < 2000 || $y > $year + 1 || (int) substr( $current, 0, 4 ) <= $year + 2
            || ! checkdate( $m, $d, $y ) || ! CalendarUtils::checkDate( $y, $m, $d, true ) ) {
            return null;
        }
        $converted = CalendarUtils::toGregorian( $y, $m, $d );
        $wrong = vsprintf( '%04d-%02d-%02d', $converted ) . ' ' . $parts[4];
        return hash_equals( $wrong, $current ) ? $reference : null;
    }

    public function guard( $order ): void {
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_date_created' ) ) {
            return;
        }
        $date = $order->get_date_created( 'edit' );
        if ( $date && (int) $date->format( 'Y' ) > (int) gmdate( 'Y' ) + 2 ) {
            // Never silently rewrite a financial record during an unrelated save.
            throw new \RuntimeException( 'تاریخ سفارش نامعتبر است؛ ذخیره متوقف شد تا تاریخ میلادی صحیح بررسی شود.' );
        }
    }
}
