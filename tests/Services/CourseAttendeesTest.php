<?php
if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key, $single = true) {
        if ($key === '_linked_product_id') { return 99; }
        return [];
    }
}
if (!function_exists('wc_get_orders')) {
    function wc_get_orders($args) {
        return [ new class { public function get_user_id(){ return 10; } }, new class { public function get_user_id(){ return 20; } } ];
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
if (!function_exists('get_user_meta')) {
    function get_user_meta($id, $key, $single = true) {
        if ($key === 'club_name') { return 'ClubName'.$id; }
        if ($key === 'coach_id' || $key === 'club_id') { return 100 + $id; }
        return $key.'_'.$id;
    }
}
if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;

if (!class_exists('WP_Post')) { class WP_Post { public $ID; public $post_type; } }
if (!class_exists('WP_User')) { class WP_User { public $ID; public $display_name; public $user_email; } }

class CourseAttendeesTest extends TestCase {
    public function test_render_attendees_box_outputs_users(): void {
        if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        $post = new WP_Post();
        $post->ID = 1;
        ob_start();
        (new Courses())->render_attendees_box($post);
        $html = ob_get_clean();
        $this->assertStringContainsString('<table id="crm-attendees-table"', $html);
        $this->assertStringContainsString('billing_phone_10', $html);
    }
}
