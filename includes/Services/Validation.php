<?php
namespace IMAOCustom\Services;

class Validation {
    public static function required( $value, string $label ): ?string {
        if ( $value === null || $value === '' ) {
            return sprintf( '%s الزامی است.', $label );
        }
        return null;
    }

    public static function national_id( string $code ): ?string {
        if ( ! preg_match( '/^\d{10}$/', $code ) ) {
            return 'کد ملی نامعتبر است.';
        }
        return null;
    }
}
