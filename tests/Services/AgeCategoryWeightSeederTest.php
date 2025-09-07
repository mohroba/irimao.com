<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\AgeCategoryWeightSeeder;

if (!class_exists('WP_Term')) {
    class WP_Term { public $term_id; public function __construct($id){ $this->term_id = $id; } }
}

class AgeCategoryWeightSeederTest extends TestCase
{
    public function test_seed_runs_only_once(): void
    {
        if (!function_exists('add_action')) {
            function add_action($hook, $func) { $GLOBALS['actions'][$hook][] = $func; }
        }
        if (!function_exists('get_option')) {
            function get_option($name) { return $GLOBALS['options'][$name] ?? false; }
        }
        if (!function_exists('update_option')) {
            function update_option($name, $value) { $GLOBALS['options'][$name] = $value; }
        }
        if (!function_exists('get_term_by')) {
            function get_term_by($field, $value, $tax) { return new WP_Term($GLOBALS['parents'][$value] ?? 0); }
        }
        if (!function_exists('term_exists')) {
            function term_exists($slug, $tax, $parent) { return false; }
        }
        if (!function_exists('wp_insert_term')) {
            function wp_insert_term($name, $tax, $args) { $GLOBALS['inserted'][] = $args + ['name' => $name]; }
        }
        if (!function_exists('sanitize_title')) {
            function sanitize_title($title) { return strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title)); }
        }

        $GLOBALS['parents'] = [
            'toddlers-7-11'   => 1,
            'teenagers-12-14' => 2,
            'youth-15-17'     => 3,
            'adults-18-38'    => 4,
        ];

        $svc = new AgeCategoryWeightSeeder();
        $svc->register();
        foreach ($GLOBALS['actions']['init'] as $cb) { $cb(); }
        $first = count($GLOBALS['inserted'] ?? []);
        $this->assertGreaterThan(0, $first);
        $this->assertTrue(($GLOBALS['options']['imao_weight_terms_seeded'] ?? false) === 1);
        foreach ($GLOBALS['actions']['init'] as $cb) { $cb(); }
        $this->assertSame($first, count($GLOBALS['inserted']));
    }
}
