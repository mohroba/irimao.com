<?php

use IMAOCustom\Services\Endpoints\Wallet;
use PHPUnit\Framework\TestCase;

class WalletOrderNeedsPaymentTest extends TestCase {
    public function test_wallet_only_order_skips_gateway(): void {
        $wallet = new Wallet();
        $order  = new class {
            public function get_meta( $key ) {
                $meta = [
                    'crm_wallet_planned_use' => 100.0,
                    'crm_wallet_gateway_due' => 0.0,
                ];

                return $meta[ $key ] ?? 0.0;
            }
        };

        $result = $wallet->order_needs_payment( true, $order, [] );

        $this->assertFalse( $result );
    }

    public function test_gateway_amount_still_requires_payment(): void {
        $wallet = new Wallet();
        $order  = new class {
            public function get_meta( $key ) {
                $meta = [
                    'crm_wallet_planned_use' => 50.0,
                    'crm_wallet_gateway_due' => 10.0,
                ];

                return $meta[ $key ] ?? 0.0;
            }
        };

        $result = $wallet->order_needs_payment( true, $order, [] );

        $this->assertTrue( $result );
    }
}

