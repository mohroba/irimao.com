<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\ChangePassword;

class ChangePasswordEndpointTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) { function add_rewrite_endpoint( $name, $places ) { $GLOBALS['endpoints'][] = $name; } }
        if ( ! function_exists( 'add_filter' ) ) { function add_filter( $hook, $func ) { $GLOBALS['filters'][$hook][] = $func; } }
        if ( ! function_exists( 'apply_filters' ) ) { function apply_filters( $hook, $value ) { foreach ( $GLOBALS['filters'][$hook] ?? [] as $f ) { $value = $f( $value ); } return $value; } }
        if ( ! function_exists( 'add_action' ) ) { function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; } }
        if ( ! function_exists( 'do_action' ) ) { function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } } }
        if ( ! function_exists( 'do_shortcode' ) ) { function do_shortcode( $tag ) { return "[{$tag}]"; } }
        if ( ! function_exists( 'add_shortcode' ) ) { function add_shortcode( $tag, $func ) { $GLOBALS['shortcodes'][$tag] = $func; } }
        if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
        if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
        $GLOBALS['endpoints'] = $GLOBALS['filters'] = $GLOBALS['actions'] = $GLOBALS['shortcodes'] = [];
    }

    public function test_change_password_endpoint(): void {
        $endpoint = new ChangePassword();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'change-password', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'change-password', $items );
        ob_start();
        do_action( 'woocommerce_account_change-password_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( '[crm_change_password]', $out );
    }
}
