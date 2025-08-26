<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionShortcodesTest extends TestCase {
    public function test_competitions_list_has_container_and_header(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $svc = new Competitions();
        $output = $svc->competitions_list_shortcode();
        $this->assertStringContainsString( 'class="sd-container"', $output );
        $this->assertStringContainsString( 'class="sd-header"', $output );
        $this->assertStringContainsString( 'crm-competition-table', $output );
        $this->assertStringContainsString( 'striped', $output );
    }

    public function test_competition_details_uses_course_design(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $svc = new Competitions();
        $output = $svc->competition_details_shortcode();
        $this->assertStringContainsString( 'class="crm-single-course"', $output );
        $this->assertStringContainsString( 'class="striped"', $output );
    }

    /** @runInSeparateProcess */
    public function test_competition_details_shows_all_fields(): void {
        require_once __DIR__ . '/stubs.php';
        if ( ! function_exists( 'shortcode_atts' ) ) {
            function shortcode_atts( $pairs, $atts, $shortcode = '' ) { return array_merge( $pairs, $atts ); }
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $id, $key, $single = true ) { return $key === 'identity_verified_professional' ? 'approved' : 'male'; }
        }
        if ( ! function_exists( 'wp_get_post_terms' ) ) {
            function wp_get_post_terms( $id, $tax, $args = [] ) { return []; }
        }
        if ( ! function_exists( 'wc_get_cart_url' ) ) {
            function wc_get_cart_url() { return '/cart'; }
        }
        if ( ! function_exists( 'get_the_terms' ) ) {
            function get_the_terms( $id, $tax ) { return []; }
        }
        if ( ! function_exists( 'wp_list_pluck' ) ) {
            function wp_list_pluck( $list, $field ) {
                $out = [];
                foreach ( $list as $item ) {
                    if ( is_array( $item ) && isset( $item[ $field ] ) ) {
                        $out[] = $item[ $field ];
                    } elseif ( is_object( $item ) && isset( $item->$field ) ) {
                        $out[] = $item->$field;
                    }
                }
                return $out;
            }
        }
        if ( ! function_exists( 'is_wp_error' ) ) {
            function is_wp_error( $thing ) { return false; }
        }
        $svc  = new Competitions();
        $html = $svc->competition_details_shortcode( [ 'id' => 20 ] );
        foreach ( Competitions::detail_fields() as $label ) {
            $this->assertStringContainsString( $label, $html );
        }
        foreach ( [ 'نوع مسابقه', 'هیئت', 'جنسیت', 'سطح' ] as $label ) {
            $this->assertStringContainsString( $label, $html );
        }
    }

    /** @runInSeparateProcess */
    public function test_competitions_list_filters_by_registration_dates(): void {
        require_once __DIR__ . '/stubs.php';
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $id, $key, $single = true ) { return 'male'; }
        }
        if ( ! function_exists( 'wp_reset_postdata' ) ) {
            function wp_reset_postdata() {}
        }
        if ( ! class_exists( 'WP_Query' ) ) {
            eval( 'class WP_Query { public static $args; public function __construct( $args ){ self::$args = $args; } public function have_posts(){ return false; } }' );
        }
        $svc  = new Competitions();
        $html = $svc->competitions_list_shortcode();
        $this->assertStringContainsString( 'ثبت نامی', $html );
        $args = WP_Query::$args;
        $this->assertArrayHasKey( 'meta_query', $args );
        $this->assertSame( 'registration_start', $args['meta_query'][0]['key'] );
        $this->assertSame( '<=', $args['meta_query'][0]['compare'] );
        $this->assertSame( 'registration_end', $args['meta_query'][1]['key'] );
        $this->assertSame( '>=', $args['meta_query'][1]['compare'] );
    }
}
