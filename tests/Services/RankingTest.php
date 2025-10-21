<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Ranking;
use ReflectionMethod;

if ( ! class_exists( 'RankingTestWpdb' ) ) {
    class RankingTestWpdb {
        public $prefix = 'wp_';
        public function prepare( $query, ...$args ) { return vsprintf( $query, $args ); }
        public function get_results( $query ) {
            if ( strpos( $query, 'GROUP BY weight_class, user_id' ) !== false ) {
                return [
                    (object) [ 'weight_class' => 10, 'user_id' => 1, 'pts' => 30 ],
                    (object) [ 'weight_class' => 10, 'user_id' => 2, 'pts' => 20 ],
                    (object) [ 'weight_class' => 11, 'user_id' => 3, 'pts' => 15 ],
                ];
            }
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
    }
}

class RankingTest extends TestCase {
    protected $backupGlobals = false;

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
            function get_userdata( $user_id ) {
                $name = $GLOBALS['test_user_names'][ $user_id ] ?? "User $user_id";
                return (object) [ 'display_name' => $name ];
            }
        }
        if ( ! function_exists( 'get_avatar_url' ) ) {
            function get_avatar_url( $id ) { return 'avatar-' . $id; }
        }
        if ( ! function_exists( 'get_the_title' ) ) {
            function get_the_title( $id ) { return "Title $id"; }
        }
        if ( ! function_exists( 'get_term' ) ) {
            function get_term( $id, $taxonomy ) {
                if ( isset( $GLOBALS['test_terms'][ $taxonomy ][ $id ] ) ) {
                    return (object) $GLOBALS['test_terms'][ $taxonomy ][ $id ];
                }
                return (object) [ 'term_id' => $id, 'name' => "Term $id", 'slug' => "term-$id", 'parent' => 0 ];
            }
        }
        if ( ! function_exists( 'get_term_by' ) ) {
            function get_term_by( $field, $value, $taxonomy ) {
                foreach ( $GLOBALS['test_terms'][ $taxonomy ] ?? [] as $term ) {
                    if ( isset( $term[ $field ] ) && $term[ $field ] === $value ) {
                        return (object) $term;
                    }
                }
                return false;
            }
        }
        if ( ! function_exists( 'wp_list_pluck' ) ) {
            function wp_list_pluck( $list, $field ) { return array_map( fn( $o ) => $o->$field, $list ); }
        }
        if ( ! function_exists( 'shortcode_atts' ) ) {
            function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, (array) $atts ); }
        }
        if ( ! function_exists( 'esc_url' ) ) {
            function esc_url( $url ) { return $url; }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            function esc_html( $str ) { return $str; }
        }
        if ( ! function_exists( 'esc_attr' ) ) {
            function esc_attr( $str ) { return $str; }
        }
        if ( ! function_exists( 'is_wp_error' ) ) {
            function is_wp_error( $thing ) { return false; }
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
        $GLOBALS['wpdb'] = new RankingTestWpdb();
        $GLOBALS['test_user_meta']  = [
            1 => [ 'gender' => 'male', 'personal_photo' => 'photo-1' ],
            2 => [ 'gender' => 'female', 'personal_photo' => 'photo-2' ],
            3 => [ 'gender' => 'male', 'personal_photo' => 'photo-3' ],
        ];
        $GLOBALS['test_user_names'] = [
            1 => 'Alpha',
            2 => 'Bravo',
            3 => 'Charlie',
        ];
        $GLOBALS['test_terms']      = [
            'age_category' => [
                5  => [ 'term_id' => 5, 'name' => 'نونهالان', 'slug' => 'youth', 'parent' => 0 ],
                10 => [ 'term_id' => 10, 'name' => '۴۰-۴۵', 'slug' => '40-45', 'parent' => 5 ],
                11 => [ 'term_id' => 11, 'name' => '۴۵-۵۰', 'slug' => '45-50', 'parent' => 5 ],
            ],
            'gender'       => [
                1 => [ 'term_id' => 1, 'name' => 'مردان', 'slug' => 'men' ],
                2 => [ 'term_id' => 2, 'name' => 'زنان', 'slug' => 'women' ],
            ],
        ];
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

    public function test_rankings_overview_shortcode_outputs_grouped_tables(): void {
        $service = new Ranking();
        $html    = $service->rankings_overview_shortcode();

        $this->assertStringContainsString( 'crm-rankings-overview', $html );
        $this->assertStringContainsString( 'crm-rankings-overview__gender-title', $html );
        $this->assertStringContainsString( 'crm-rankings-overview__weight-title', $html );
        $this->assertStringContainsString( 'Alpha', $html );
    }

    public function test_rankings_overview_shortcode_applies_filters(): void {
        $service = new Ranking();
        $html    = $service->rankings_overview_shortcode( [ 'gender' => 'women', 'limit' => 1 ] );

        $this->assertStringContainsString( 'زنان', $html );
        $this->assertStringNotContainsString( 'Alpha', $html );
        $this->assertStringContainsString( 'Bravo', $html );
        $this->assertStringNotContainsString( 'Charlie', $html );
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

    public function test_match_weight_option_prefers_explicit_term_id(): void {
        $service = new Ranking();
        $method  = new ReflectionMethod( Ranking::class, 'match_weight_option' );
        $method->setAccessible( true );

        $options = [
            [
                'id'                => 10,
                'label'             => 'Cadet - 40-45',
                'normalized_term'   => '40-45',
                'normalized_label'  => 'cadet - 40-45',
                'normalized_parent' => 'cadet',
                'parent_id'         => 5,
            ],
        ];

        $meta = [
            'weight_class_term' => 10,
            'weight_class'      => '40-45',
            'age_category'      => 'Cadet',
        ];

        $this->assertSame( 10, $method->invoke( $service, $options, $meta ) );
    }

    public function test_match_weight_option_uses_age_category_to_disambiguate(): void {
        $service = new Ranking();
        $method  = new ReflectionMethod( Ranking::class, 'match_weight_option' );
        $method->setAccessible( true );

        $options = [
            [
                'id'                => 10,
                'label'             => 'Cadet - 40-45',
                'normalized_term'   => '40-45',
                'normalized_label'  => 'cadet - 40-45',
                'normalized_parent' => 'cadet',
                'parent_id'         => 5,
            ],
            [
                'id'                => 11,
                'label'             => 'Junior - 40-45',
                'normalized_term'   => '40-45',
                'normalized_label'  => 'junior - 40-45',
                'normalized_parent' => 'junior',
                'parent_id'         => 6,
            ],
        ];

        $meta = [
            'weight_class' => '40-45',
            'age_category' => 'Junior',
        ];

        $this->assertSame( 11, $method->invoke( $service, $options, $meta ) );
    }
}
