<?php

namespace IMAOCustom\Services {
    class WalletCheckoutLedger {
        public function record( $order ) { return $GLOBALS['committed_ledger_record'] ?? null; }
        public function debit( $order, $user, $amount ) {
            ++$GLOBALS['ledger_debits'];
            return [ 'amount' => $amount, 'balance_before' => 100, 'balance_after' => 100 - $amount ];
        }
        public function refund( ...$args ) { ++$GLOBALS['ledger_refunds']; return []; }
    }
    class MonthlyTuition {
        public function prepare_order( $order ) {
            ++$GLOBALS['enrollment_checks'];
            if ( $GLOBALS['enrollment_error'] ) throw new \RuntimeException( 'duplicate enrollment' );
        }
    }
}

namespace IMAOCustom\Services\Endpoints {
    function wc_get_order( $id ) { return $GLOBALS['lifecycle_order']; }
    function wc_price( $amount ) { return (string) $amount; }
}

namespace {
    use IMAOCustom\Services\Endpoints\Wallet;
    use PHPUnit\Framework\TestCase;

    final class LifecycleWalletOrder {
        public array $meta = [ 'crm_wallet_planned_use' => 30, 'crm_wallet_gateway_due' => 0, 'crm_wallet_processed' => 'pending', 'crm_wallet_original_total' => 30, 'crm_used_wallet' => 30 ];
        public int $completed = 0;
        public function get_id() { return 20; }
        public function get_customer_id() { return 5; }
        public function get_meta( $key ) { return $this->meta[$key] ?? ''; }
        public function get_items() { return []; }
        public function is_paid() { return false; }
        public function get_total() { return 0; }
        public function set_total( $value ) {}
        public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
        public function delete_meta_data( $key ) { unset( $this->meta[$key] ); }
        public function add_order_note( $note ) {}
        public function save() {}
        public function payment_complete() {
            if ( $this->meta['crm_wallet_processed'] !== 'completed' ) throw new RuntimeException( 'Missing committed marker' );
            ++$this->completed;
        }
    }

    final class WalletCheckoutLifecycleTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['ledger_debits'] = $GLOBALS['ledger_refunds'] = $GLOBALS['enrollment_checks'] = 0;
            $GLOBALS['enrollment_error'] = false;
            unset( $GLOBALS['committed_ledger_record'] );
            $GLOBALS['lifecycle_order'] = new LifecycleWalletOrder();
        }

        public function test_validation_failure_never_debits_or_completes_order(): void {
            $GLOBALS['enrollment_error'] = true;
            try { ( new Wallet() )->process_wallet_checkout( 20 ); $this->fail(); }
            catch ( RuntimeException $error ) {
                $this->assertSame( 'duplicate enrollment', $error->getMessage() );
                $this->assertSame( 0, $GLOBALS['ledger_debits'] );
                $this->assertSame( 0, $GLOBALS['lifecycle_order']->completed );
            }
        }

        public function test_valid_persisted_order_is_checked_debited_and_completed_once(): void {
            $wallet = new Wallet();
            $wallet->process_wallet_checkout( 20 );
            $wallet->process_wallet_checkout( 20 );
            $this->assertSame( 1, $GLOBALS['enrollment_checks'] );
            $this->assertSame( 1, $GLOBALS['ledger_debits'] );
            $this->assertSame( 1, $GLOBALS['lifecycle_order']->completed );
        }

        public function test_cancelled_unsettled_intent_is_not_refunded(): void {
            ( new Wallet() )->refund_wallet( 20 );
            $this->assertSame( 0, $GLOBALS['ledger_refunds'] );
        }

        public function test_confirmed_debit_is_refunded_only_once(): void {
            $GLOBALS['lifecycle_order']->meta['crm_wallet_processed'] = 'completed';
            $GLOBALS['lifecycle_order']->meta['crm_wallet_payment_breakdown'] = [ 'wallet_deducted' => 30 ];
            $wallet = new Wallet();
            $wallet->refund_wallet( 20 );
            $wallet->refund_wallet( 20 );
            $this->assertSame( 1, $GLOBALS['ledger_refunds'] );
            $this->assertSame( 'refunded', $GLOBALS['lifecycle_order']->meta['crm_wallet_processed'] );
        }

        public function test_committed_debit_can_be_refunded_after_a_metadata_save_failure(): void {
            $GLOBALS['committed_ledger_record'] = [ 'amount' => 30, 'status' => 'debited' ];
            ( new Wallet() )->refund_wallet( 20 );
            $this->assertSame( 1, $GLOBALS['ledger_refunds'] );
        }
    }
}
