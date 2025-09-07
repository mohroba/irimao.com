<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;

class CourseShortcodesTest extends TestCase {
    public function test_course_list_table_is_striped(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $id, $key, $single = true ) {
                return $key === 'gender' ? 'male' : '1385/01/01';
            }
        }
        $svc = new CourseList();
        $output = $svc->shortcode();
        $this->assertStringContainsString( 'crm-course-table', $output );
        $this->assertStringContainsString( 'striped', $output );
    }
}
