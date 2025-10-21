<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CoachStudents;

class CoachStudentsEndpointTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $places ) { $GLOBALS['endpoints'][] = $name; }
            function add_filter( $hook, $func ) { $GLOBALS['filters'][ $hook ][] = $func; }
            function apply_filters( $hook, $value ) { foreach ( $GLOBALS['filters'][ $hook ] ?? [] as $f ) { $value = $f( $value ); } return $value; }
            function add_action( $hook, $func ) { $GLOBALS['actions'][ $hook ][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][ $hook ] ?? [] as $f ) { $f(); } }
            function add_shortcode( $tag, $func ) { $GLOBALS['shortcodes'][ $tag ] = $func; }
            function do_shortcode( $tag ) { return "[{$tag}]"; }
            function is_user_logged_in() { return true; }
            function wp_get_current_user() { return (object) [ 'roles' => [ 'coach' ] ]; }
            if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
            if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
        }
        $GLOBALS['endpoints'] = $GLOBALS['filters'] = $GLOBALS['actions'] = $GLOBALS['shortcodes'] = [];
    }

    public function test_registers_endpoint_and_shortcode(): void {
        $endpoint = new CoachStudents();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'coach-students', $GLOBALS['endpoints'] );
        $items = apply_filters( 'woocommerce_account_menu_items', [] );
        $this->assertArrayHasKey( 'coach-students', $items );
        $this->assertArrayHasKey( 'crm_coach_students', $GLOBALS['shortcodes'] );
        ob_start();
        do_action( 'woocommerce_account_coach-students_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( '[crm_coach_students]', $out );
    }
}
