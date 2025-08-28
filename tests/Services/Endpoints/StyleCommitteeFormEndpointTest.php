<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\StyleCommitteeFormEndpoint;

class StyleCommitteeFormEndpointTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $places ) { $GLOBALS['endpoints'][] = $name; }
            function add_filter( $hook, $func ) { $GLOBALS['filters'][$hook][] = $func; }
            function apply_filters( $hook, $value ) { foreach ( $GLOBALS['filters'][$hook] ?? [] as $f ) { $value = $f( $value ); } return $value; }
            function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } }
            function add_shortcode( $tag, $func ) { $GLOBALS['shortcodes'][$tag] = $func; }
            function register_activation_hook( $file, $func ) {}
            if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
            if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
            if ( ! defined( 'IMAO_PLUGIN_FILE' ) ) { define( 'IMAO_PLUGIN_FILE', __FILE__ ); }
        }
        $GLOBALS['endpoints'] = $GLOBALS['filters'] = $GLOBALS['actions'] = $GLOBALS['shortcodes'] = [];
    }

    public function test_registers_endpoint_and_shortcode(): void {
        $endpoint = new StyleCommitteeFormEndpoint();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'style-committe-form', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'style-committe-form', $items );
        $this->assertArrayHasKey( 'crm_style_committe_form', $GLOBALS['shortcodes'] );
    }
}
