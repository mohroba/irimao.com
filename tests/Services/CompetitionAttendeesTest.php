<?php
if (!function_exists('get_post_meta')) {
    $GLOBALS['test_meta'] = ['_linked_product_id' => 99];
    function get_post_meta($id, $key, $single = true) {
        return $GLOBALS['test_meta'][$key] ?? [];
    }
}
if (!function_exists('wc_get_orders')) {
    class TestOrder {
        private int $id;
        public function __construct(int $id) { $this->id = $id; }
        public function get_user_id() { return $this->id; }
    }
    $GLOBALS['captured_orders_args'] = null;
    $GLOBALS['wc_get_orders_called'] = false;
    function wc_get_orders($args) {
        $GLOBALS['wc_get_orders_called'] = true;
        $GLOBALS['captured_orders_args'] = $args;
        return [ new TestOrder(10), new TestOrder(20) ];
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
        return $key.'_'.$id;
    }
}
if (!function_exists('esc_html')) { function esc_html($v){ return $v; } }

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

if (!class_exists('WP_Post')) { class WP_Post { public $ID; public $post_type; } }
if (!class_exists('WP_User')) { class WP_User { public $ID; public $display_name; public $user_email; } }

class CompetitionAttendeesTest extends TestCase {
    public function test_render_attendees_box_outputs_users(): void {
        if (!class_exists(Competitions::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        $post = new WP_Post();
        $post->ID = 1;
        ob_start();
        (new Competitions())->render_attendees_box($post);
        $html = ob_get_clean();
        $this->assertStringContainsString('<table id="crm-attendees-table"', $html);
        $this->assertStringContainsString('billing_phone_10', $html);
        $this->assertSame(99, $GLOBALS['captured_orders_args']['product'] ?? null);
    }

    public function test_render_attendees_box_without_product_returns_empty(): void {
        if (!class_exists(Competitions::class)) { $this->markTestSkipped('Plugin not loaded.'); }
        $GLOBALS['test_meta']['_linked_product_id'] = 0;
        $GLOBALS['wc_get_orders_called'] = false;
        $post = new WP_Post();
        $post->ID = 2;
        ob_start();
        (new Competitions())->render_attendees_box($post);
        $html = ob_get_clean();
        $this->assertStringContainsString('شرکت‌کننده‌ای ثبت نشده است', $html);
        $this->assertFalse($GLOBALS['wc_get_orders_called']);
    }
}
