<?php

namespace IMAOCustom\Services;

/** Balance, audit log and per-order idempotency are committed together. */
final class WalletCheckoutLedger {
    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'imao_wallet_checkout';
    }

    public function install(): void {
        if ( get_option( 'imao_wallet_checkout_version' ) !== '20261115090000'
            && ( new \IMAOCustom\Migrations\CreateWalletCheckoutLedger20261115090000() )->up() ) {
            update_option( 'imao_wallet_checkout_version', '20261115090000', false );
        }
    }

    public function debit( int $order_id, int $user_id, float $amount ): array {
        return $this->mutate( $order_id, $user_id, $amount, false, false );
    }

    public function record( int $order_id ): ?array {
        global $wpdb;
        $record = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d', $order_id ), ARRAY_A );
        if ( $wpdb->last_error !== '' ) {
            throw new \RuntimeException( 'خواندن سابقه تراکنش کیف پول ناموفق بود.' );
        }
        return $record ?: null;
    }

    /** A legacy refund requires a verified completed debit, never a planned amount. */
    public function refund( int $order_id, int $user_id, float $amount, bool $legacy = false ): array {
        return $this->mutate( $order_id, $user_id, $amount, true, $legacy );
    }

    private function must( $result ): void {
        if ( $result === false ) {
            throw new \RuntimeException( 'ثبت تراکنش کیف پول ناموفق بود؛ دوباره تلاش کنید.' );
        }
    }

    private function mutate( int $order_id, int $user_id, float $amount, bool $refund, bool $legacy ): array {
        global $wpdb;
        if ( $order_id <= 0 || $user_id <= 0 || ! is_finite( $amount ) || $amount <= 0 ) {
            throw new \InvalidArgumentException( 'اطلاعات تراکنش کیف پول معتبر نیست.' );
        }
        $amount = round( $amount, 2 );
        $table = self::table();
        foreach ( [ $wpdb->users, $wpdb->usermeta, $table ] as $name ) {
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $name ) );
            if ( $wpdb->last_error !== '' || strtoupper( (string) $engine ) !== 'INNODB' ) {
                throw new \RuntimeException( 'تراکنش امن کیف پول نیازمند جداول InnoDB است.' );
            }
        }
        $this->must( $wpdb->query( 'START TRANSACTION' ) );
        try {
            if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $user_id ) ) || $wpdb->last_error !== '' ) {
                throw new \RuntimeException( 'حساب کیف پول پیدا نشد.' );
            }
            $record = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id=%d FOR UPDATE", $order_id ), ARRAY_A );
            if ( $wpdb->last_error !== '' ) {
                throw new \RuntimeException( 'خواندن سابقه تراکنش کیف پول ناموفق بود.' );
            }
            if ( $record && ( (int) $record['user_id'] !== $user_id || abs( (float) $record['amount'] - $amount ) > 0.01 ) ) {
                throw new \RuntimeException( 'اطلاعات سفارش با سابقه کیف پول مطابقت ندارد.' );
            }
            if ( $record && ( ! $refund || $record['status'] === 'refunded' ) ) {
                if ( ! $refund && $record['status'] !== 'debited' ) {
                    throw new \RuntimeException( 'وجه این سفارش قبلاً برگشت داده شده است.' );
                }
                $this->must( $wpdb->query( 'COMMIT' ) );
                return array_merge( $record, [ 'changed' => false ] );
            }
            if ( $refund && ! $record && ! $legacy ) {
                throw new \RuntimeException( 'کسر وجه تأییدشده‌ای برای برگشت وجود ندارد.' );
            }
            $balance_rows = $this->meta_rows( $user_id, '_wallet_balance' );
            $before = $balance_rows ? (float) $balance_rows[0]->meta_value : 0.0;
            if ( ! $refund && $before + 0.001 < $amount ) {
                throw new \RuntimeException( 'موجودی کیف پول برای پرداخت کامل کافی نیست؛ سبد خرید را به‌روز کنید.' );
            }
            $after = round( $before + ( $refund ? $amount : -$amount ), 2 );
            $this->write_meta( $user_id, '_wallet_balance', $after, $balance_rows );
            $logs = $this->meta_rows( $user_id, 'crm_wallet_log' );
            $log = $logs ? maybe_unserialize( $logs[0]->meta_value ) : [];
            $log = is_array( $log ) ? $log : [];
            $log[] = [ 'date' => current_time( 'mysql' ), 'amount' => $refund ? $amount : -$amount,
                'note' => ( $refund ? 'بازگشت وجه واقعی کیف پول سفارش #' : 'پرداخت با کیف پول سفارش #' ) . $order_id,
                'order_id' => $order_id, 'event' => $refund ? 'checkout_refund' : 'checkout_debit' ];
            $this->write_meta( $user_id, 'crm_wallet_log', maybe_serialize( $log ), $logs );
            $new = [ 'order_id' => $order_id, 'user_id' => $user_id, 'amount' => $amount,
                'balance_before' => $before, 'balance_after' => $after, 'status' => $refund ? 'refunded' : 'debited',
                'origin' => $legacy && ! $record ? 'legacy_refund' : 'checkout', 'created_at' => current_time( 'mysql' ) ];
            if ( $refund ) {
                $new['refunded_at'] = current_time( 'mysql' );
            }
            if ( $record ) {
                $this->must( $wpdb->update( $table, [ 'status' => 'refunded', 'refunded_at' => $new['refunded_at'] ], [ 'order_id' => $order_id ] ) );
            } else {
                $this->must( $wpdb->insert( $table, $new ) );
            }
            $this->must( $wpdb->query( 'COMMIT' ) );
            return array_merge( $new, [ 'changed' => true ] );
        } catch ( \Throwable $error ) {
            $wpdb->query( 'ROLLBACK' );
            throw $error;
        } finally {
            wp_cache_delete( $user_id, 'user_meta' );
        }
    }

    private function meta_rows( int $user_id, string $key ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id FOR UPDATE", $user_id, $key ) );
        if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
            throw new \RuntimeException( 'خواندن اطلاعات کیف پول ناموفق بود.' );
        }
        return $rows;
    }

    private function write_meta( int $user_id, string $key, $value, array $rows ): void {
        global $wpdb;
        $result = $rows
            ? $wpdb->update( $wpdb->usermeta, [ 'meta_value' => $value ], [ 'user_id' => $user_id, 'meta_key' => $key ] )
            : $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user_id, 'meta_key' => $key, 'meta_value' => $value ] );
        $this->must( $result );
    }
}
