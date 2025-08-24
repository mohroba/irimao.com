<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\UserCourseList;

class UserCourseListShortcodeTest extends TestCase {
    /** @runInSeparateProcess */
    public function test_shortcode_ignores_competitions(): void {
        require_once __DIR__ . '/stubs.php';
        $svc  = new UserCourseList();
        $html = $svc->shortcode();
        $this->assertStringContainsString( 'Course Title', $html );
        $this->assertStringNotContainsString( 'Competition Title', $html );
    }
}
