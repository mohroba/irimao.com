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
        if ( ! function_exists( 'absint' ) ) {
            function absint( $value ) { return abs( (int) $value ); }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            function esc_html( $s ) { return $s; }
        }
    }

    public function test_wallet_manager_rows_include_nonce_and_external_form_controls(): void {
        $svc = new Wallet();
        $users = [ (object) [ 'ID' => 1, 'display_name' => 'Test User', 'user_registered' => '2026-11-10 10:00:00' ] ];
        $rows = $svc->wallet_manager_rows( $users, 50 );
        $this->assertSame( 51, $rows[0]['row_number'] );
        $this->assertStringContainsString( 'name="_wpnonce"', $rows[0]['actions'] );
        $this->assertStringContainsString( 'form="crm-wallet-form-1"', $rows[0]['amount'] );
        $this->assertStringContainsString( 'form="crm-wallet-form-1"', $rows[0]['memo'] );
        foreach ( [ 'add', 'sub', 'set' ] as $action ) {
            $this->assertStringContainsString( 'value="' . $action . '"', $rows[0]['actions'] );
        }
    }
}
