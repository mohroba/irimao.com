<?php
namespace IMAOCustom\Helpers {
    function get_user_meta( $id, $key, $single = true ) {
        return $GLOBALS['test_user_meta'][ $id ][ $key ] ?? 0;
    }
    function update_user_meta( $id, $key, $value ) {
        $GLOBALS['test_user_meta'][ $id ][ $key ] = $value;
    }
}

namespace IMAOCustom\Services\Endpoints {
    function get_post_meta( $id, $key, $single = true ) {
        return $GLOBALS['test_post_meta'][ $id ][ $key ] ?? '';
    }
    function get_user_meta( $id, $key, $single = true ) {
        return $GLOBALS['test_user_meta'][ $id ][ $key ] ?? 0;
    }
    function update_user_meta( $id, $key, $value ) {
        $GLOBALS['test_user_meta'][ $id ][ $key ] = $value;
    }
    function current_time( $type = '' ) { return 'now'; }
    function get_post_type( $id ) { return $GLOBALS['test_post_types'][ $id ] ?? ''; }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use IMAOCustom\Services\Endpoints\Wallet;

    if ( ! class_exists( 'WC_Order' ) ) {
        class WC_Order {
            public function get_customer_id() { return 2; }
            public function get_meta( $key ) { return 0; }
            public function get_items() { return [ new WC_Order_Item_Product() ]; }
        }
        class WC_Order_Item_Product {
            public function get_product_id() { return 55; }
            public function get_total() { return 200; }
        }
        function wc_get_order( $id ) { return new WC_Order(); }
    }

    class WalletPayoutRoleTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['test_post_meta']  = [];
            $GLOBALS['test_user_meta']  = [];
            $GLOBALS['test_post_types'] = [];
        }

        public function test_coach_role_payout(): void {
            $GLOBALS['test_post_meta'] = [
                55 => [ '_linked_post_id' => 10 ],
                10 => [ '_course_payouts' => [ [ 'role' => 'coach', 'type' => 'percent', 'value' => 10 ] ] ],
            ];
            $GLOBALS['test_post_types'] = [ 10 => 'course' ];
            $GLOBALS['test_user_meta'] = [
                2   => [ 'coach_id' => 100, '_wallet_balance' => 0 ],
                100 => [ '_wallet_balance' => 0 ],
            ];
            $svc = new Wallet();
            $svc->after_payment( 1 );
            $this->assertSame( 20.0, Wallet::get_balance( 100 ) );
        }
    }
}
