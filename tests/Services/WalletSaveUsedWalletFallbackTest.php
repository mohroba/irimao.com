<?php

use IMAOCustom\Services\Endpoints\Wallet;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WC_Session_Stub' ) ) {
    class WC_Session_Stub {
        private $data = [];
        public function get( $key ) {
            return $this->data[ $key ] ?? null;
        }
        public function set( $key, $value ): void {
            $this->data[ $key ] = $value;
        }
    }
}

if ( ! function_exists( 'WC' ) ) {
    function WC() {
        return (object) [ 'session' => $GLOBALS['wc_session_stub'] ?? null ];
    }
}

class WalletSaveUsedWalletFallbackTest extends TestCase {
    private WC_Session_Stub $session;

    protected function setUp(): void {
        parent::setUp();
        $this->session = new WC_Session_Stub();
        $GLOBALS['wc_session_stub'] = $this->session;
        $GLOBALS['test_user_meta']  = [];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wc_session_stub'], $GLOBALS['test_user_meta'] );
        parent::tearDown();
    }

    public function test_wallet_only_order_falls_back_when_session_missing(): void {
        $this->session->set( 'crm_use_wallet', true );

        $order = new class {
            public float $total = 20000.0;
            public array $meta = [];
            public array $notes = [];
            public bool $completed = false;
            public bool $saved = false;

            public function get_customer_id() { return 5; }
            public function get_total() { return $this->total; }
            public function set_total( $amount ): void { $this->total = (float) $amount; }
            public function update_meta_data( $key, $value ): void { $this->meta[ $key ] = $value; }
            public function add_order_note( $note ): void { $this->notes[] = $note; }
            public function payment_complete(): void { $this->completed = true; }
            public function save(): void { $this->saved = true; }
        };

        $GLOBALS['test_user_meta'][5] = [
            '_wallet_balance' => 20000.0,
            'crm_wallet_log'  => [],
        ];

        $wallet = new Wallet();
        $wallet->save_used_wallet( $order, [] );

        $this->assertSame( 0.0, $order->total );
        $this->assertSame( 20000.0, $order->meta['crm_wallet_planned_use'] );
        $this->assertSame( 0.0, $order->meta['crm_wallet_gateway_due'] );
        $this->assertTrue( $order->completed );
        $this->assertTrue( $order->saved );
        $this->assertSame( 0.0, $order->meta['crm_wallet_balance_after'] );
    }

    public function test_partial_wallet_use_sets_remaining_gateway_amount(): void {
        $this->session->set( 'crm_use_wallet', true );

        $order = new class {
            public float $total = 20000.0;
            public array $meta = [];
            public bool $completed = false;

            public function get_customer_id() { return 6; }
            public function get_total() { return $this->total; }
            public function set_total( $amount ): void { $this->total = (float) $amount; }
            public function update_meta_data( $key, $value ): void { $this->meta[ $key ] = $value; }
            public function add_order_note( $note ): void {}
            public function payment_complete(): void { $this->completed = true; }
            public function save(): void {}
        };

        $GLOBALS['test_user_meta'][6] = [
            '_wallet_balance' => 5000.0,
            'crm_wallet_log'  => [],
        ];

        $wallet = new Wallet();
        $wallet->save_used_wallet( $order, [] );

        $this->assertSame( 15000.0, $order->total );
        $this->assertSame( 5000.0, $order->meta['crm_wallet_planned_use'] );
        $this->assertSame( 15000.0, $order->meta['crm_wallet_gateway_due'] );
        $this->assertFalse( $order->completed );
    }
}
