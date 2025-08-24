<?php
namespace IMAOCustom\Services {
    if (!function_exists(__NAMESPACE__.'\\wp_nonce_field')) { function wp_nonce_field($a,$b){} }
    if (!function_exists(__NAMESPACE__.'\\esc_attr')) { function esc_attr($v){ return $v; } }
    if (!function_exists(__NAMESPACE__.'\\esc_html')) { function esc_html($v){ return $v; } }
    if (!function_exists(__NAMESPACE__.'\\get_post_meta')) { function get_post_meta($id,$key,$single=true){ return ''; } }
    if (!function_exists(__NAMESPACE__.'\\update_post_meta')) { function update_post_meta($id,$key,$val){ $GLOBALS['updated_meta'][$key] = $val; } }
    if (!function_exists(__NAMESPACE__.'\\sanitize_text_field')) { function sanitize_text_field($v){ return is_string($v)?trim($v):$v; } }
    if (!function_exists(__NAMESPACE__.'\\absint')) { function absint($v){ return abs(intval($v)); } }
    if (!function_exists(__NAMESPACE__.'\\get_current_screen')) { function get_current_screen(){ return $GLOBALS['__test_screen'] ?? null; } }
    if (!function_exists(__NAMESPACE__.'\\wp_enqueue_style')) { function wp_enqueue_style($handle,$src='',$deps=[],$ver=''){} }
    if (!function_exists(__NAMESPACE__.'\\wp_enqueue_script')) { function wp_enqueue_script($handle,$src='',$deps=[],$ver='',$in_footer=false){} }
    if (!function_exists(__NAMESPACE__.'\\wp_add_inline_script')) { function wp_add_inline_script($handle,$data){ $GLOBALS['inline_scripts'][$handle] = $data; } }
    if (!function_exists(__NAMESPACE__.'\\plugin_dir_url')) { function plugin_dir_url($file){ return '/'; } }
}

namespace {
    use PHPUnit\Framework\TestCase;
    use IMAOCustom\Services\Courses;

    if (!class_exists('WP_Post')) { class WP_Post { public $ID; public $post_type; } }

    class CoursePriceFieldTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['updated_meta'] = [];
            $GLOBALS['inline_scripts'] = [];
            $GLOBALS['__test_screen'] = (object)['post_type' => 'course'];
        }

        protected function tearDown(): void {
            unset($GLOBALS['__test_screen']);
        }

        public function test_render_details_box_price_field_attributes(): void {
            if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
            $post = new WP_Post();
            $post->ID = 1;
            $post->post_type = 'course';
            ob_start();
            (new Courses())->render_details_box($post);
            $html = ob_get_clean();
            $this->assertStringContainsString('id="price"', $html);
            $this->assertStringContainsString('type="number"', $html);
            $this->assertStringContainsString('min="0"', $html);
            $this->assertStringContainsString('step="1000"', $html);
        }

        public function test_save_meta_sanitizes_price(): void {
            if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
            $_POST = ['crm_details_nonce' => 'nonce', 'price' => '-5000'];
            $course = new Courses();
            $post = new WP_Post();
            $post->post_type = 'course';
            $course->save_meta(1, $post, true);
            $this->assertSame(5000, $GLOBALS['updated_meta']['price']);
        }

        public function test_enqueue_admin_assets_has_no_price_separator_script(): void {
            if (!class_exists(Courses::class)) { $this->markTestSkipped('Plugin not loaded.'); }
            (new Courses())->enqueue_admin_assets();
            $script = $GLOBALS['inline_scripts']['imao-jdp'] ?? '';
            $this->assertStringNotContainsString('crm-price', $script);
        }
    }
}
