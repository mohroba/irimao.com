<?php

namespace IMAOCustom\Helpers;

class Wallet {
    private const META_KEY = '_wallet_balance';

    public static function get( int $user_id = 0 ): float {
        $user_id = $user_id ?: get_current_user_id();
        return (float) get_user_meta( $user_id, self::META_KEY, true );
    }

    public static function set( int $user_id, float $amount ): void {
        update_user_meta( $user_id, self::META_KEY, $amount );
    }

    public static function add( int $user_id, float $amount ): void {
        self::set( $user_id, self::get( $user_id ) + $amount );
    }

    public static function deduct( int $user_id, float $amount ): void {
        self::set( $user_id, max( 0, self::get( $user_id ) - $amount ) );
    }
}
