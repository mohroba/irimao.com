<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;

class CourseListShortcodeTest extends TestCase {
    /** @runInSeparateProcess */
    public function test_course_list_filters_by_registration_dates_and_gender(): void {
        require_once __DIR__ . '/stubs.php';
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $id, $key, $single = true ) { return 'male'; }
        }
        if ( ! function_exists( 'wp_reset_postdata' ) ) { function wp_reset_postdata() {} }
        if ( ! class_exists( 'WP_Query' ) ) {
            eval( 'class WP_Query { public static $args; public function __construct( $args ){ self::$args = $args; } public function have_posts(){ return false; } }' );
        }
        $svc  = new CourseList();
        $html = $svc->shortcode();
        $this->assertStringContainsString( 'ثبت نامی', $html );
        $args = WP_Query::$args;
        $this->assertSame( 'registration_start', $args['meta_query'][0]['key'] );
        $this->assertSame( '<=', $args['meta_query'][0]['compare'] );
        $this->assertSame( 'registration_end', $args['meta_query'][1]['key'] );
        $this->assertSame( '>=', $args['meta_query'][1]['compare'] );
        $this->assertSame( 'men', $args['tax_query'][0]['terms'] );
    }
}
