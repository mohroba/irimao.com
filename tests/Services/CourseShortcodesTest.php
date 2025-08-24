<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;

class CourseShortcodesTest extends TestCase {
    public function test_course_list_table_is_striped(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $svc = new CourseList();
        $output = $svc->shortcode();
        $this->assertStringContainsString( 'crm-course-table', $output );
        $this->assertStringContainsString( 'striped', $output );
    }
}
