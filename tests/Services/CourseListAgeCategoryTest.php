<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Endpoints\CourseList;

class CourseListAgeCategoryTest extends TestCase {
    public function test_list_shows_only_parent_age_terms(): void {
        require_once __DIR__ . '/stubs.php';
        if (!function_exists('get_user_meta')) {
            function get_user_meta($id, $key, $single = true) {
                return $key === 'gender' ? 'male' : '1385/01/01';
            }
        }
        if (!function_exists('taxonomy_exists')) {
            function taxonomy_exists($t) { return $t === 'age_category'; }
        }
        if (!function_exists('wp_reset_postdata')) {
            function wp_reset_postdata() {}
        }
        if (!class_exists('WP_Query')) {
            eval('class WP_Query { public $i=0; public function __construct($args){} public function have_posts(){return $this->i<1;} public function the_post(){ $this->i++; $GLOBALS["post"]=(object)["ID"=>10]; } }');
        }
        if (!function_exists('get_post')) {
            function get_post() { return $GLOBALS['post']; }
        }
        if (!function_exists('get_the_ID')) {
            function get_the_ID() { return $GLOBALS['post']->ID; }
        }
        if (!function_exists('get_the_terms')) {
            function get_the_terms($id, $tax) {
                if ($tax === 'age_category') {
                    return [
                        (object)['name' => 'Parent Age', 'parent' => 0],
                        (object)['name' => 'Child Weight', 'parent' => 1],
                    ];
                }
                return [(object)['name' => 'Term', 'parent' => 0]];
            }
        }
        if (!function_exists('wp_list_pluck')) {
            function wp_list_pluck($list, $field) {
                $out = [];
                foreach ($list as $item) {
                    if (is_object($item) && isset($item->$field)) {
                        $out[] = $item->$field;
                    }
                }
                return $out;
            }
        }
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) { return false; }
        }
        $svc = new CourseList();
        $html = $svc->shortcode();
        $this->assertStringContainsString('Parent Age', $html);
        $this->assertStringNotContainsString('Child Weight', $html);
    }
}
