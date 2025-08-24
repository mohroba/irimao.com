<?php
if (!function_exists('get_user_meta')) {
    function get_user_meta($id, $key, $single = true) {
        if ($key === 'club_name') { return 'ClubName'.$id; }
        if ($key === 'coach_id' || $key === 'club_id') { return 100 + $id; }
        return $key.'_'.$id;
    }
}
if (!function_exists('get_user_by')) {
    function get_user_by($field, $value) {
        $u = new WP_User();
        $u->ID = $value;
        $u->display_name = 'User'.$value;
        $u->user_email = 'u'.$value.'@example.com';
        return $u;
    }
}
if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;

if (!class_exists('WP_User')) { class WP_User { public $ID; public $display_name; public $user_email; } }

class CoursesExportStub extends Courses {
    public function csv(array $users): string { return $this->build_csv($users); }
}

class CourseAttendeeExportTest extends TestCase {
    public function test_csv_contains_meta_values(): void {
        if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        $u = new WP_User();
        $u->ID = 1; $u->display_name = 'User1'; $u->user_email = 'u1@example.com';
        $csv = (new CoursesExportStub())->csv([$u]);
        $this->assertStringContainsString('billing_phone_1', $csv);
        $this->assertStringContainsString('User1', $csv);
    }
}
