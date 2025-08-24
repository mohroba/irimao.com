<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\ClubStudents;

class ClubStudentsEndpointTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $flags ) {
                $GLOBALS['endpoints'][] = $name;
            }
        }
        if ( ! function_exists( 'add_action' ) ) {
            function add_action( $hook, $func ) {
                $GLOBALS['actions'][$hook][] = $func;
            }
        }
        if ( ! function_exists( 'do_action' ) ) {
            function do_action( $hook ) {
                foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) {
                    $f();
                }
            }
        }
        if ( ! function_exists( 'is_user_logged_in' ) ) {
            function is_user_logged_in() { return true; }
        }
        if ( ! function_exists( 'get_current_user_id' ) ) {
            function get_current_user_id() { return 10; }
        }
        if ( ! function_exists( 'get_users' ) ) {
            function get_users( $args ) {
                return [
                    (object) [ 'ID' => 1, 'display_name' => 'Alpha', 'user_email' => 'a@example.com' ],
                    (object) [ 'ID' => 2, 'display_name' => 'Beta',  'user_email' => 'b@example.com' ],
                ];
            }
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $uid, $key, $single = true ) {
                $data = [
                    1 => [ 'billing_phone' => '111', 'national_id' => '001' ],
                    2 => [ 'billing_phone' => '222', 'national_id' => '002' ],
                ];
                return $data[ $uid ][ $key ] ?? '';
            }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            function esc_html( $str ) { return $str; }
        }
        if ( ! function_exists( 'esc_attr' ) ) {
            function esc_attr( $str ) { return $str; }
        }
        if ( ! function_exists( 'plugin_dir_url' ) ) {
            function plugin_dir_url( $path ) { return '/'; }
        }
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            function wp_enqueue_style( $handle ) { $GLOBALS['styles'][] = $handle; }
        }
        if ( ! function_exists( 'wp_enqueue_script' ) ) {
            function wp_enqueue_script( $handle ) { $GLOBALS['scripts'][] = $handle; }
        }
        if ( ! function_exists( 'wp_add_inline_script' ) ) {
            function wp_add_inline_script( $handle, $code ) { $GLOBALS['inline'][ $handle ] = $code; }
        }
        if ( ! defined( 'EP_ROOT' ) ) { define( 'EP_ROOT', 1 ); }
        if ( ! defined( 'EP_PAGES' ) ) { define( 'EP_PAGES', 1 ); }
        $GLOBALS['endpoints'] = $GLOBALS['actions'] = $GLOBALS['styles'] = $GLOBALS['scripts'] = $GLOBALS['inline'] = [];
    }

    public function test_endpoint_registration_and_output(): void {
        $endpoint = new ClubStudents();
        $endpoint->register();
        do_action( 'init' );
        $this->assertContains( 'club-students', $GLOBALS['endpoints'] );
        ob_start();
        do_action( 'woocommerce_account_club-students_endpoint' );
        $out = ob_get_clean();
        $this->assertStringContainsString( 'club-students-table', $out );
        $this->assertStringContainsString( '111', $out );
        $this->assertContains( 'imao-dt', $GLOBALS['scripts'] );
    }
}
