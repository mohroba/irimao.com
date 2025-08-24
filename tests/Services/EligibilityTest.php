<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Eligibility;

// Stub WordPress functions for testing
if (!function_exists('get_post_meta')) {
    function get_post_meta($post_id, $key, $single = false) {
        return $GLOBALS['test_post_meta'][$post_id][$key] ?? '';
    }
}
if (!function_exists('get_post_type')) {
    function get_post_type($post_id) {
        return $GLOBALS['test_post_type'][$post_id] ?? '';
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() { return $GLOBALS['test_logged_in'] ?? false; }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return $GLOBALS['test_current_user'] ?? 0; }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($uid, $key, $single = false) {
        return $GLOBALS['test_user_meta'][$uid][$key] ?? '';
    }
}
if (!function_exists('wc_add_notice')) {
    function wc_add_notice($msg, $type = '') { $GLOBALS['test_notices'][] = [$msg, $type]; }
}
if (!function_exists('wp_get_post_terms')) {
    function wp_get_post_terms($post_id, $taxonomy, $args = []) {
        return $GLOBALS['test_terms'][$post_id][$taxonomy] ?? [];
    }
}

class EligibilityTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['test_post_meta']   = [];
        $GLOBALS['test_post_type']   = [];
        $GLOBALS['test_user_meta']   = [];
        $GLOBALS['test_terms']       = [];
        $GLOBALS['test_notices']     = [];
        $GLOBALS['test_logged_in']   = true;
        $GLOBALS['test_current_user']= 1;
    }

    public function test_disallows_gender_mismatch(): void {
        $GLOBALS['test_post_meta'][10]['_linked_post_id'] = 20;
        $GLOBALS['test_post_type'][20] = 'course';
        $GLOBALS['test_user_meta'][1]['gender'] = 'male';
        $GLOBALS['test_user_meta'][1]['identity_verified_professional'] = 'approved';
        $GLOBALS['test_terms'][20]['gender'] = ['female'];

        $svc = new Eligibility();
        $this->assertFalse($svc->validate(true, 10, 1));
        $this->assertNotEmpty($GLOBALS['test_notices']);
    }
}
