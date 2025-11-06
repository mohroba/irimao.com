<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\Wallet;

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

class WalletStoreFlagTest extends TestCase {
    private $session;

    protected function setUp(): void {
        parent::setUp();
        $this->session = new WC_Session_Stub();
        $GLOBALS['wc_session_stub'] = $this->session;
        $_POST = [];
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wc_session_stub'] );
        $_POST = [];
        parent::tearDown();
    }

    public function test_store_wallet_flag_reads_serialized_post_data(): void {
        $_POST['post_data'] = 'billing_first_name=Test&crm_use_wallet=1';

        $wallet = new Wallet();
        $wallet->store_wallet_flag();

        $this->assertTrue( (bool) $this->session->get( 'crm_use_wallet' ) );
    }

    public function test_store_wallet_flag_resets_when_unchecked(): void {
        $this->session->set( 'crm_use_wallet', true );
        $this->session->set( 'crm_wallet_use_amount', 150 );
        $this->session->set( 'crm_wallet_cart_total', 200 );

        $wallet = new Wallet();
        $wallet->store_wallet_flag();

        $this->assertFalse( (bool) $this->session->get( 'crm_use_wallet' ) );
        $this->assertSame( 0.0, (float) $this->session->get( 'crm_wallet_use_amount' ) );
        $this->assertSame( 0.0, (float) $this->session->get( 'crm_wallet_cart_total' ) );
    }
}
