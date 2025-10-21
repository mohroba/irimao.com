<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Ranking;

class RankingTest extends TestCase {
    protected function setUp(): void {
        if ( ! defined( 'ABSPATH' ) ) {
            $dir = sys_get_temp_dir() . '/wp/';
            if ( ! is_dir( $dir . 'wp-admin/includes' ) ) {
                mkdir( $dir . 'wp-admin/includes', 0777, true );
            }
            file_put_contents( $dir . 'wp-admin/includes/upgrade.php', '<?php function dbDelta($sql){}' );
            define( 'ABSPATH', $dir );
        }
        if ( ! function_exists( 'get_userdata' ) ) {
            function get_userdata( $user_id ) { return (object) [ 'display_name' => "User $user_id" ]; }
            function get_user_meta( $user_id, $key, $single = true ) { return ''; }
            function get_avatar_url( $id ) { return 'avatar'; }
            function get_the_title( $id ) { return "Title $id"; }
            function get_term( $id, $taxonomy ) { return (object) [ 'name' => 'Class' ]; }
            function is_user_logged_in() { return true; }
            function get_current_user_id() { return 1; }
            function wp_list_pluck( $list, $field ) { return array_map( fn( $o ) => $o->$field, $list ); }
            function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, (array) $atts ); }
            function esc_url( $url ) { return $url; }
            function esc_html( $str ) { return $str; }
        }
        if ( ! function_exists( 'add_rewrite_endpoint' ) ) {
            function add_rewrite_endpoint( $name, $places ) { $GLOBALS['add_rewrite_endpoint_called'] = true; }
        }
        if ( ! function_exists( 'flush_rewrite_rules' ) ) {
            function flush_rewrite_rules() { $GLOBALS['flush_rewrite_rules_called'] = true; }
        }
        if ( ! defined( 'EP_ROOT' ) ) {
            define( 'EP_ROOT', 1 );
        }
        if ( ! defined( 'EP_PAGES' ) ) {
            define( 'EP_PAGES', 2 );
        }
        $GLOBALS['wpdb'] = new class {
            public $prefix = 'wp_';
            public function prepare( $query, ...$args ) { return vsprintf( $query, $args ); }
            public function get_results( $query ) {
                if ( strpos( $query, 'GROUP BY user_id' ) !== false ) {
                    return [ (object) [ 'user_id' => 1, 'pts' => 10 ], (object) [ 'user_id' => 2, 'pts' => 5 ] ];
                }
                return [];
            }
            public function get_var( $query ) { return 0; }
            public function get_charset_collate() { return ''; }
            public function replace( $table, $data, $format ) {}
            public function insert( $table, $data, $format ) {}
            public function esc_like( $text ) { return addcslashes( (string) $text, '%_' ); }
        };
    }

    public function test_competition_rankings_shortcode_outputs_table(): void {
        $service = new Ranking();
        $html    = $service->competition_rankings_shortcode( [ 'id' => 1 ] );
        $this->assertStringContainsString( 'crm-rank-table', $html );
    }

    public function test_competition_rankings_shortcode_requires_id(): void {
        $service = new Ranking();
        $html    = $service->competition_rankings_shortcode();
        $this->assertStringContainsString( 'مسابقه نامشخص', $html );
    }

    public function test_my_rankings_shortcode_outputs_section(): void {
        $service = new Ranking();
        $html    = $service->my_rankings_shortcode();
        $this->assertStringContainsString( 'crm-my-rank', $html );
        $this->assertStringContainsString( 'sd-container', $html );
        $this->assertStringContainsString( 'sd-header', $html );
        $this->assertStringContainsString( 'shop_table', $html );
    }

    public function test_activate_registers_endpoint_and_flushes_rules(): void {
        $service = new Ranking();
        $GLOBALS['add_rewrite_endpoint_called'] = false;
        $GLOBALS['flush_rewrite_rules_called']  = false;
        $service->activate();
        $this->assertTrue( $GLOBALS['add_rewrite_endpoint_called'] );
        $this->assertTrue( $GLOBALS['flush_rewrite_rules_called'] );
    }

    public function test_deactivate_flushes_rules(): void {
        $service = new Ranking();
        $GLOBALS['flush_rewrite_rules_called'] = false;
        $service->deactivate();
        $this->assertTrue( $GLOBALS['flush_rewrite_rules_called'] );
    }
}
