<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionsListTitleTest extends TestCase {
    /** @runInSeparateProcess */
    public function test_competitions_list_shows_post_titles(): void {
        if ( ! function_exists('get_user_meta') ) {
            function get_user_meta( $id, $key, $single = true ) {
                return $key === 'gender' ? 'male' : '1385/01/01';
            }
        }
        if ( ! class_exists( 'WP_Query' ) ) {
            eval( 'class WP_Query { public $i=0; public function __construct($args){} public function have_posts(){return $this->i<1;} public function the_post(){ $this->i++; $GLOBALS["post"]=(object)["ID"=>20]; } }' );
        }
        if ( ! function_exists('get_post') ) {
            function get_post() { return $GLOBALS['post']; }
        }
        if ( ! function_exists('get_the_ID') ) {
            function get_the_ID() { return $GLOBALS['post']->ID; }
        }
        if ( ! function_exists('wp_get_post_terms') ) {
            function wp_get_post_terms( $id, $tax, $args = [] ) { return []; }
        }
        if ( ! function_exists('wp_reset_postdata') ) { function wp_reset_postdata() {} }
        require_once __DIR__ . '/stubs.php';
        $svc  = new Competitions();
        $html = $svc->competitions_list_shortcode();
        $this->assertStringContainsString( 'Competition Title', $html );
    }
}
