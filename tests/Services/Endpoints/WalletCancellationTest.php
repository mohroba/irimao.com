<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\Wallet;

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
    define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! function_exists( 'wc_get_orders' ) ) {
    function wc_get_orders( $args ) {
        return $GLOBALS['wc_get_orders_return'] ?? [];
    }
}
if ( ! class_exists( 'WC_Order' ) ) {
    class WC_Order {
        public $status = 'pending';
        public $date;
        public $meta = [];
        public $updated_to = null;
        public function __construct( $date ) { $this->date = $date; }
        public function get_date_created() { return new DateTime( $this->date ); }
        public function get_status() { return $this->status; }
        public function update_status( $new, $note = '' ) { $this->status = $new; $this->updated_to = $new; }
        public function get_meta( $key ) { return $this->meta[$key] ?? null; }
    }
}

class WalletCancellationTest extends TestCase {
    public function test_cancel_unpaid_orders_marks_old_orders_cancelled(): void {
        $old = new WC_Order( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS - 5 ) );
        $old->meta['wallet_topup'] = 100;
        $recent = new WC_Order( gmdate( 'Y-m-d H:i:s', time() - 10 ) );
        $recent->meta['wallet_topup'] = 200;
        $GLOBALS['wc_get_orders_return'] = [ $old, $recent ];

        $svc = new Wallet();
        $svc->cancel_unpaid_orders();

        $this->assertSame( 'cancelled', $old->status );
        $this->assertSame( 'pending', $recent->status );
    }
}
