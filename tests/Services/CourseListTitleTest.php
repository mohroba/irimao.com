<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;

class CourseListTitleTest extends TestCase {
    /** @runInSeparateProcess */
    public function test_course_list_shows_post_titles(): void {
        if ( ! function_exists('get_user_meta') ) {
            function get_user_meta( $id, $key, $single = true ) {
                return $key === 'gender' ? 'male' : '1385/01/01';
            }
        }
        if ( ! function_exists('taxonomy_exists') ) {
            function taxonomy_exists( $t ) { return false; }
        }
        if ( ! class_exists( 'WP_Query' ) ) {
            eval( 'class WP_Query { public $i=0; public function __construct($args){} public function have_posts(){return $this->i<1;} public function the_post(){ $this->i++; $GLOBALS["post"]=(object)["ID"=>10]; } }' );
        }
        if ( ! function_exists('get_post') ) {
            function get_post() { return $GLOBALS['post']; }
        }
        if ( ! function_exists('get_the_ID') ) {
            function get_the_ID() { return $GLOBALS['post']->ID; }
        }
        if ( ! function_exists('wp_reset_postdata') ) { function wp_reset_postdata() {} }
        require_once __DIR__ . '/stubs.php';
        $svc  = new CourseList();
        $html = $svc->shortcode();
        $this->assertStringContainsString( 'Course Title', $html );
    }
}
