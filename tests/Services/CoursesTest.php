<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Courses;

class CoursesTest extends TestCase {
    protected function setUp(): void {
        if (!function_exists('register_post_type')) {
            function register_post_type($post_type, $args) { $GLOBALS['registered_post_types'][$post_type] = $args; }
        }
        if (!function_exists('register_taxonomy')) {
            function register_taxonomy($taxonomy, $object_type, $args) { $GLOBALS['registered_taxonomies'][$taxonomy] = $args; }
        }
        if (!function_exists('add_action')) {
            function add_action($hook, $func) { $GLOBALS['actions'][$hook][] = $func; }
        }
        if (!function_exists('add_filter')) {
            function add_filter($hook, $func) { $GLOBALS['filters'][$hook][] = $func; }
        }
        if (!function_exists('do_action')) {
            function do_action($hook) { foreach ($GLOBALS['actions'][$hook] ?? [] as $f) { $f(); } }
        }
        if (!function_exists('term_exists')) {
            function term_exists($slug, $tax) { return true; }
        }
    }

    public function test_register_makes_gender_hierarchical(): void {
        $service = new Courses();
        $service->register();
        do_action('init');
        $this->assertArrayHasKey('gender', $GLOBALS['registered_taxonomies']);
        $this->assertTrue($GLOBALS['registered_taxonomies']['gender']['hierarchical']);
    }
}
