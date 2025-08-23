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

    public static function file( array $file, array $allowed_mimes, int $max_size, string $size_label ): ?string {
        $mime = $file['type'] ?? '';
        $size = (int) ( $file['size'] ?? 0 );
        if ( ! in_array( $mime, $allowed_mimes, true ) ) {
            return 'فرمت فایل نامعتبر است.';
        }
        if ( $size > $max_size ) {
            return sprintf( 'حجم فایل باید حداکثر %s باشد.', $size_label );
        }
        return null;
    }
}