<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;
use IMAOCustom\Services\Endpoints\UserCourseList;

class CourseEndpointsTest extends TestCase {
    public function test_course_list_menu_item(): void {
        $service = new CourseList();
        $items = $service->menu_item([]);
        $this->assertArrayHasKey('course-list', $items);
    }

    public function test_user_course_list_menu_item(): void {
        $service = new UserCourseList();
        $items = $service->menu_item([]);
        $this->assertArrayHasKey('user-course-list', $items);
    }
}
