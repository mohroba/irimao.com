<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\Wallet;

class WalletAdminPageNonceTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'current_user_can' ) ) {
            function current_user_can( $cap ) { return true; }
        }
        if ( ! function_exists( 'get_users' ) ) {
            function get_users( $args = [] ) {
                return [ (object) [ 'ID' => 1, 'display_name' => 'Test User', 'user_email' => 'user@example.com' ] ];
            }
        }
        if ( ! function_exists( 'wp_nonce_field' ) ) {
            function wp_nonce_field( $action, $name = '_wpnonce', $referer = true, $echo = true ) {
                $field = '<input type="hidden" name="' . $name . '" value="nonce" />';
                if ( $echo ) { echo $field; }
                return $field;
            }
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $user_id, $key, $single = true ) { return 0; }
        }
        if ( ! function_exists( 'wc_price' ) ) {
            function wc_price( $amount ) { return (string) $amount; }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            function esc_html( $s ) { return $s; }
        }
    }

    public function test_wallet_manager_page_includes_nonce_field(): void {
        $svc = new Wallet();
        ob_start();
        $svc->wallet_manager_page();
        $output = ob_get_clean();
        $this->assertStringContainsString( 'name="_wpnonce"', $output );
    }
}
