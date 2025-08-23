<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\Wallet;

class WalletServiceTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'get_user_meta' ) ) {
            $GLOBALS['test_user_meta'] = [];
            function get_user_meta( $user_id, $key, $single = true ) {
                return $GLOBALS['test_user_meta'][ $user_id ][ $key ] ?? 0;
            }
            function update_user_meta( $user_id, $key, $value ) {
                $GLOBALS['test_user_meta'][ $user_id ][ $key ] = $value;
            }
            function get_current_user_id() { return 1; }
        }
    }

    public function test_balance_operations(): void {
        $user_id = 1;
        Wallet::set_balance( $user_id, 100 );
        $this->assertSame( 100.0, Wallet::get_balance( $user_id ) );

        Wallet::add_balance( $user_id, 50 );
        $this->assertSame( 150.0, Wallet::get_balance( $user_id ) );

        Wallet::deduct_balance( $user_id, 70 );
        $this->assertSame( 80.0, Wallet::get_balance( $user_id ) );
    }
}

