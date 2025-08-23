<?php

namespace IMAOCustom\Helpers;

class UserMeta {
    public static function get( int $user_id, string $key, $default = '' ) {
        $value = get_user_meta( $user_id, $key, true );
        return $value !== '' ? $value : $default;
    }

    public static function set( int $user_id, string $key, $value ): void {
        update_user_meta( $user_id, $key, self::sanitize( $value ) );
    }

    private static function sanitize( $value ) {
        if ( is_string( $value ) ) {
            return sanitize_text_field( $value );
        }
        return $value;
    }
}

