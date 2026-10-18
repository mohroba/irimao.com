<?php

namespace IMAOCustom\Helpers;

class Wallet {
    private const META_KEY = '_wallet_balance';

    public static function get_meta_key(): string {
        return self::META_KEY;
    }

    public static function get( int $user_id = 0 ): float {
        $user_id = $user_id ?: get_current_user_id();
        return (float) get_user_meta( $user_id, self::META_KEY, true );
    }

    public static function set( int $user_id, float $amount ): void {
        self::mutate( $user_id, $amount, 'set' );
    }

    public static function add( int $user_id, float $amount ): void {
        self::mutate( $user_id, $amount, 'add' );
    }

    public static function deduct( int $user_id, float $amount ): void {
        self::mutate( $user_id, $amount, 'deduct' );
    }

    private static function must( $result ): void {
        if ( $result === false ) {
            throw new \RuntimeException( 'Wallet database operation failed.' );
        }
    }

    private static function mutate( int $user_id, float $amount, string $operation ): void {
        global $wpdb;

        if ( $user_id <= 0 || ! is_finite( $amount ) || ( $operation === 'deduct' && $amount < 0 ) ) {
            throw new \InvalidArgumentException( 'Invalid wallet user or amount.' );
        }

        foreach ( [ $wpdb->users, $wpdb->usermeta ] as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare(
                'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table
            ) );
            if ( $wpdb->last_error !== '' || strtoupper( (string) $engine ) !== 'INNODB' ) {
                throw new \RuntimeException( 'Atomic wallet updates require InnoDB user tables.' );
            }
        }

        self::must( $wpdb->query( 'START TRANSACTION' ) );
        try {
            // Match tuition's lock order, including wallets without metadata yet.
            $user = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user_id ) );
            if ( $wpdb->last_error !== '' || ! $user ) {
                throw new \RuntimeException( 'Unable to lock wallet user.' );
            }
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT umeta_id,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id FOR UPDATE",
                $user_id, self::META_KEY
            ) );
            if ( $wpdb->last_error !== '' || ! is_array( $rows ) ) {
                throw new \RuntimeException( 'Unable to read wallet balance.' );
            }
            $balance = $rows ? (float) $rows[0]->meta_value : 0.0;
            $new = $amount;
            if ( $operation === 'add' ) {
                $new = $balance + $amount;
            } elseif ( $operation === 'deduct' ) {
                $new = max( 0.0, $balance - $amount );
            }
            if ( ! is_finite( $new ) ) {
                throw new \RuntimeException( 'Wallet balance is not finite.' );
            }

            // Update all duplicate rows, matching WordPress and tuition semantics.
            if ( $rows ) {
                self::must( $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $new ],
                    [ 'user_id' => $user_id, 'meta_key' => self::META_KEY ], [ '%s' ], [ '%d', '%s' ] ) );
            } else {
                self::must( $wpdb->insert( $wpdb->usermeta,
                    [ 'user_id' => $user_id, 'meta_key' => self::META_KEY, 'meta_value' => $new ], [ '%d', '%s', '%s' ] ) );
            }
            self::must( $wpdb->query( 'COMMIT' ) );
        } catch ( \Throwable $error ) {
            self::must( $wpdb->query( 'ROLLBACK' ) );
            throw $error;
        } finally {
            wp_cache_delete( $user_id, 'user_meta' );
        }
    }
}
