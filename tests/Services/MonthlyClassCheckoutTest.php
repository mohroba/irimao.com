<?php

namespace IMAOCustom\Services {
    function absint( $value ) { return abs( (int) $value ); }
    function check_admin_referer( $action, $name ) { $GLOBALS['checkout_nonce_checked'] = [ $action, $name ]; }
    function WC() { return $GLOBALS['checkout_wc']; }
    function wc_get_checkout_url() { return '/checkout/'; }
    function wp_safe_redirect( $url ) { $GLOBALS['checkout_redirect'] = $url; throw new \RuntimeException( 'test-redirect' ); }
    function wp_die( $message, ...$args ) { throw new \RuntimeException( $message ); }
    function esc_html( $value ) { return $value; }
    function get_post( $id ) { return (object) ( $id === 100 ? [ 'post_type' => 'imao_monthly_class', 'post_status' => 'publish' ] : [ 'post_type' => 'club_application', 'post_status' => 'publish', 'post_author' => 2 ] ); }
    function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['test_post_meta'][$id][$key] ?? ''; }
    function get_user_meta( $id, $key, $single = true ) { return [ 'coach_id' => 2, 'referrer_id' => 3, 'identity_verified_professional' => 'approved' ][$key] ?? ''; }
    function get_userdata( $id ) { return (object) [ 'ID' => $id, 'roles' => [ 'subscriber', 'coach' ] ]; }
    function wp_json_encode( $value ) { return json_encode( $value ); }
    function current_time( $format ) { return ( new \Morilog\Jalali\Jalalian( 1405, 8, 18 ) )->toCarbon()->format( 'Y-m-d' ); }
    function wp_timezone() { return new \DateTimeZone( 'Asia/Tehran' ); }
}

namespace {
    use IMAOCustom\Services\MonthlyTuition;
    use PHPUnit\Framework\TestCase;
    if ( ! function_exists( 'get_woocommerce_currency' ) ) { function get_woocommerce_currency() { return 'IRT'; } }
    final class MonthlyClassCheckoutTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['test_post_meta'] = [ 10 => [ '_imao_monthly_class_id' => 100 ], 100 => [ '_class_coach_id' => 2, '_class_club_id' => 200,
                '_class_approved' => 'yes', '_class_fee' => 300000, '_class_fee_unit' => 'toman', '_class_shares' => [ 'referrer' => 20, 'coach' => 30, 'club' => 50 ] ] ];
            $GLOBALS['wpdb'] = new class {
                public string $prefix = 'test_';
                public int $owner = 0;
                public function prepare( $query, ...$args ) { return [ $query, $args ]; }
                public function get_row( $query ) { return null; }
                public function query( $query ) { $this->owner = $this->owner ?: $query[1][3]; return 1; }
                public function get_var( $query ) { return $this->owner; }
            };
        }

        private function order( int $id, float $subtotal = 130000 ): object {
            $item = new class( $subtotal ) {
                private array $meta = [];
                private float $subtotal;
                public function __construct( $subtotal ) { $this->subtotal = $subtotal; }
                public function get_product_id() { return 10; }
                public function get_quantity() { return 1; }
                public function get_subtotal() { return $this->subtotal; }
                public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
                public function add_meta_data( $key, $value, $unique = false ) { $this->meta[$key] = $value; }
                public function save() {}
            };
            return new class( $id, $item ) {
                private int $id;
                private object $item;
                public function __construct( $id, $item ) { $this->id=$id; $this->item=$item; }
                public function get_id() { return $this->id; }
                public function is_paid() { return false; }
                public function get_customer_id() { return 1; }
                public function get_currency() { return 'IRT'; }
                public function get_items() { return [ $this->item ]; }
            };
        }

        public function test_order_snapshots_proration_and_same_order_retry_is_idempotent(): void {
            $order = $this->order( 501 );
            $service = new MonthlyTuition();
            $service->prepare_order( $order );
            $service->prepare_order( $order );
            $item = $order->get_items()[0];
            $snapshot = $item->get_meta( MonthlyTuition::ITEM_SNAPSHOT );
            $this->assertSame( '1405-08', $snapshot['billing']['period'] );
            $this->assertSame( 130000.0, $snapshot['billing']['amount_toman'] );
            $this->assertSame( [ 'referrer' => 3, 'coach' => 2, 'club' => 2 ], $snapshot['recipients'] );
            $this->assertSame( 501, $GLOBALS['wpdb']->owner );
            $this->expectException( RuntimeException::class );
            $service->prepare_order( $this->order( 502 ) );
        }

        public function test_underpriced_order_is_rejected_before_enrollment_is_claimed(): void {
            $this->expectException( RuntimeException::class );
            ( new MonthlyTuition() )->prepare_order( $this->order( 501, 1 ) );
        }

        public function test_frontend_payment_adds_class_product_and_redirects_to_checkout(): void {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_GET = [ 'imao_class_action' => 'pay' ];
            $_POST = [ 'action' => 'imao_pay_monthly_class', 'class_id' => 100 ];
            $GLOBALS['test_post_meta'][100]['_class_product_id'] = 10;
            $GLOBALS['checkout_wc'] = (object) [
                'session' => new class { public bool $cookie = false; public function set_customer_session_cookie( $value ) { $this->cookie = $value; } },
                'cart' => new class { public array $added = []; public function add_to_cart( $id, $quantity ) { $this->added = [ $id, $quantity ]; return 'cart-key'; } },
            ];
            try { ( new MonthlyTuition() )->handle_frontend_checkout(); }
            catch ( RuntimeException $error ) { $this->assertSame( 'test-redirect', $error->getMessage() ); }
            $this->assertSame( [ 'imao_pay_monthly_class_100', 'imao_monthly_class_nonce' ], $GLOBALS['checkout_nonce_checked'] );
            $this->assertSame( [ 10, 1 ], $GLOBALS['checkout_wc']->cart->added );
            $this->assertTrue( $GLOBALS['checkout_wc']->session->cookie );
            $this->assertSame( '/checkout/', $GLOBALS['checkout_redirect'] );
        }
    }
}
