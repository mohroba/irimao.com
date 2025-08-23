<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\Wallet;

class WalletTest extends TestCase {
    public function test_wallet_helpers(): void {
        if ( ! function_exists( 'get_user_meta' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }

        $user_id = 123;
        Wallet::set( $user_id, 100 );
        $this->assertSame( 100.0, Wallet::get( $user_id ) );
        Wallet::add( $user_id, 50 );
        $this->assertSame( 150.0, Wallet::get( $user_id ) );
        Wallet::deduct( $user_id, 70 );
        $this->assertSame( 80.0, Wallet::get( $user_id ) );
    }
}
