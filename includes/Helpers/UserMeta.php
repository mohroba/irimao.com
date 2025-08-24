<?php

namespace IMAOCustom\Helpers;

class UserMeta {
    public static function get( int $user_id, string $key, $default = '' ) {
        $value = get_user_meta( $user_id, $key, true );
        return $value !== '' ? $value : $default;
    }

    /**
     * Retrieve multiple meta values for a user.
     *
     * Accepts a list of keys or an associative array of key => default.
     *
     * @param int   $user_id User ID.
     * @param array $keys    Meta keys.
     * @return array<string,mixed>
     */
    public static function get_many( int $user_id, array $keys ): array {
        $out = [];
        foreach ( $keys as $key => $default ) {
            if ( is_int( $key ) ) {
                $key     = $default;
                $default = '';
            }
            $out[ $key ] = self::get( $user_id, $key, $default );
        }
        return $out;
    }

    /**
     * Retrieve the gender term slug for a user.
     */
    public static function gender_slug( int $user_id ): string {
        $g = strtolower( (string) self::get( $user_id, 'gender', '' ) );
        switch ( $g ) {
            case 'male':
                return 'men';
            case 'female':
                return 'women';
            default:
                return $g;
        }
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

