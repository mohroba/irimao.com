<?php

use IMAOCustom\Services\WalletCheckoutLedger;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'maybe_serialize' ) ) {
    function maybe_serialize( $value ) { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
}
if ( ! function_exists( 'maybe_unserialize' ) ) {
    function maybe_unserialize( $value ) { return is_string( $value ) && preg_match( '/^[aObisdN]:/', $value ) ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value; }
}

final class CheckoutLedgerDatabaseFake {
    public string $prefix = 'test_';
    public string $users = 'users';
    public string $usermeta = 'usermeta';
    public string $last_error = '';
    public string $engine = 'InnoDB';
    public array $meta = [ '_wallet_balance' => 100.0 ];
    public array $ledger = [];
    public bool $fail_insert = false;
    private array $snapshot = [];
    public function prepare( $sql, ...$args ) { return [ $sql, $args ]; }
    public function get_var( $query ) { return strpos( $query[0], 'SELECT ENGINE' ) === 0 ? $this->engine : 5; }
    public function get_row( $query, $output = null ) { return $this->ledger[ $query[1][0] ] ?? null; }
    public function get_results( $query ) {
        $key = $query[1][1];
        return array_key_exists( $key, $this->meta ) ? [ (object) [ 'umeta_id' => 1, 'meta_value' => $this->meta[ $key ] ] ] : [];
    }
    public function query( $sql ) {
        if ( $sql === 'START TRANSACTION' ) $this->snapshot = [ $this->meta, $this->ledger ];
        if ( $sql === 'ROLLBACK' ) [ $this->meta, $this->ledger ] = $this->snapshot;
        return 1;
    }
    public function insert( $table, $data ) {
        if ( $table === $this->usermeta ) $this->meta[ $data['meta_key'] ] = $data['meta_value'];
        else {
            if ( $this->fail_insert ) return false;
            $this->ledger[ $data['order_id'] ] = $data;
        }
        return 1;
    }
    public function update( $table, $data, $where ) {
        if ( $table === $this->usermeta ) $this->meta[ $where['meta_key'] ] = $data['meta_value'];
        else $this->ledger[ $where['order_id'] ] = array_merge( $this->ledger[ $where['order_id'] ], $data );
        return 1;
    }
}

final class WalletCheckoutLedgerTest extends TestCase {
    private $previous;
    protected function setUp(): void {
        if ( ! defined( 'ARRAY_A' ) ) define( 'ARRAY_A', 'ARRAY_A' );
        $this->previous = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new CheckoutLedgerDatabaseFake();
    }
    protected function tearDown(): void { $GLOBALS['wpdb'] = $this->previous; }

    public function test_debit_and_retry_commit_balance_log_and_claim_once(): void {
        $ledger = new WalletCheckoutLedger();
        $first = $ledger->debit( 20, 5, 30 );
        $second = $ledger->debit( 20, 5, 30 );
        $this->assertTrue( $first['changed'] );
        $this->assertFalse( $second['changed'] );
        $this->assertSame( 70.0, $GLOBALS['wpdb']->meta['_wallet_balance'] );
        $log = maybe_unserialize( $GLOBALS['wpdb']->meta['crm_wallet_log'] );
        $this->assertCount( 1, $log );
        $this->assertSame( 20, $log[0]['order_id'] );
    }

    public function test_insufficient_balance_is_not_clamped_or_claimed(): void {
        try { ( new WalletCheckoutLedger() )->debit( 20, 5, 120 ); $this->fail(); }
        catch ( RuntimeException $error ) {
            $this->assertSame( 100.0, $GLOBALS['wpdb']->meta['_wallet_balance'] );
            $this->assertSame( [], $GLOBALS['wpdb']->ledger );
        }
    }

    public function test_sql_failure_rolls_back_balance_and_log(): void {
        $GLOBALS['wpdb']->fail_insert = true;
        try { ( new WalletCheckoutLedger() )->debit( 20, 5, 30 ); $this->fail(); }
        catch ( RuntimeException $error ) {
            $this->assertSame( [ '_wallet_balance' => 100.0 ], $GLOBALS['wpdb']->meta );
            $this->assertSame( [], $GLOBALS['wpdb']->ledger );
        }
    }

    public function test_refund_is_idempotent_and_cannot_redebit_refunded_order(): void {
        $ledger = new WalletCheckoutLedger();
        $ledger->debit( 20, 5, 30 );
        $ledger->refund( 20, 5, 30 );
        $this->assertFalse( $ledger->refund( 20, 5, 30 )['changed'] );
        $this->assertSame( 100.0, $GLOBALS['wpdb']->meta['_wallet_balance'] );
        $this->assertCount( 2, maybe_unserialize( $GLOBALS['wpdb']->meta['crm_wallet_log'] ) );
        $this->expectException( RuntimeException::class );
        $ledger->debit( 20, 5, 30 );
    }

    public function test_unverified_planned_payment_cannot_be_refunded(): void {
        $this->expectException( RuntimeException::class );
        ( new WalletCheckoutLedger() )->refund( 20, 5, 30 );
    }

    public function test_verified_legacy_refund_is_once_only(): void {
        $ledger = new WalletCheckoutLedger();
        $ledger->refund( 20, 5, 30, true );
        $ledger->refund( 20, 5, 30, true );
        $this->assertSame( 130.0, $GLOBALS['wpdb']->meta['_wallet_balance'] );
        $this->assertSame( 'legacy_refund', $GLOBALS['wpdb']->ledger[20]['origin'] );
    }

    public function test_order_claim_cannot_change_user_or_amount(): void {
        $ledger = new WalletCheckoutLedger();
        $ledger->debit( 20, 5, 30 );
        $this->expectException( RuntimeException::class );
        $ledger->debit( 20, 6, 30 );
    }

    public function test_nontransactional_engine_fails_before_any_write(): void {
        $GLOBALS['wpdb']->engine = 'MyISAM';
        $this->expectException( RuntimeException::class );
        ( new WalletCheckoutLedger() )->debit( 20, 5, 30 );
    }
}
