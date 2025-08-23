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
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            $GLOBALS['test_enqueued_styles'] = [];
            function wp_enqueue_style( $handle, $src, $deps = [], $ver = false, $media = 'all' ) {
                $GLOBALS['test_enqueued_styles'][ $handle ] = $src;
            }
        }
        if ( ! function_exists( 'is_account_page' ) ) {
            function is_account_page() {
                return $GLOBALS['test_is_account_page'] ?? false;
            }
        }
        if ( ! function_exists( 'get_post' ) ) {
            function get_post() {
                return $GLOBALS['test_post'] ?? null;
            }
        }
        if ( ! function_exists( 'has_shortcode' ) ) {
            function has_shortcode( $content, $tag ) {
                return $GLOBALS['test_has_shortcode'] ?? false;
            }
        }
        if ( ! function_exists( 'plugin_dir_url' ) ) {
            function plugin_dir_url( $file ) {
                return 'http://example.com/plugin/';
            }
        }
        if ( ! function_exists( 'is_admin' ) ) {
            function is_admin() {
                return false;
            }
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

    public function test_enqueue_assets_on_account_page(): void {
        $GLOBALS['test_enqueued_styles'] = [];
        $GLOBALS['test_is_account_page'] = true;
        $wallet = new Wallet();
        $wallet->enqueue_assets();
        $this->assertArrayHasKey( 'imao-wallet', $GLOBALS['test_enqueued_styles'] );
    }
}
