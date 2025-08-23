<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\SelfDeclaration;
use IMAOCustom\Services\Endpoints\SelfDeclarationsList;
use IMAOCustom\Services\Endpoints\SmartcardIssue;

class SelfDeclarationEndpointsTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $places ) { $GLOBALS['endpoints'][] = $name; }
            function add_filter( $hook, $func ) { $GLOBALS['filters'][$hook][] = $func; }
            function apply_filters( $hook, $value ) { foreach ( $GLOBALS['filters'][$hook] ?? [] as $f ) { $value = $f( $value ); } return $value; }
            function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } }
            function add_shortcode( $tag, $func ) { $GLOBALS['shortcodes'][$tag] = $func; }
            function register_post_type( $post_type, $args = [] ) {}
            function register_taxonomy( $taxonomy, $object_type, $args = [] ) {}
            if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
            if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
        }
        $GLOBALS['endpoints'] = $GLOBALS['filters'] = $GLOBALS['actions'] = $GLOBALS['shortcodes'] = [];
    }

    public function test_self_declaration_registers_endpoint_and_shortcode(): void {
        $endpoint = new SelfDeclaration();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'self-declare', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'self-declare', $items );
        $this->assertArrayHasKey( 'crm_self_declaration', $GLOBALS['shortcodes'] );
    }

    public function test_self_declarations_list_registers_endpoint_and_shortcode(): void {
        $endpoint = new SelfDeclarationsList();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'self-declarations-list', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'self-declarations-list', $items );
        $this->assertArrayHasKey( 'crm_self_declarations_list', $GLOBALS['shortcodes'] );
    }

    public function test_smartcard_issue_registers_endpoint_and_shortcode(): void {
        $endpoint = new SmartcardIssue();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'smartcard-issue', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'smartcard-issue', $items );
        $this->assertArrayHasKey( 'crm_smartcard_issue', $GLOBALS['shortcodes'] );
    }
}
