<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\AgeCategories;

class AgeCategoriesTest extends TestCase
{
    protected $backupGlobals = false;
    public function test_register_hooks(): void
    {
        if (!function_exists('add_action')) {
            function add_action($hook, $func) { $GLOBALS['actions'][$hook][] = $func; }
        }
        if (!function_exists('add_filter')) {
            function add_filter($hook, $func) { $GLOBALS['filters'][$hook][] = $func; }
        }
        $svc = new AgeCategories();
        $svc->register();
        $this->assertArrayHasKey( 'age_category_add_form_fields', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'age_category_edit_form_fields', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'created_age_category', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'edited_age_category', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'wp_terms_checklist_args', $GLOBALS['filters'] );
    }

    public function test_checked_terms_not_on_top(): void
    {
        if (!function_exists('add_action')) {
            function add_action($hook, $func) { $GLOBALS['actions'][$hook][] = $func; }
        }
        if (!function_exists('add_filter')) {
            function add_filter($hook, $func) { $GLOBALS['filters'][$hook][] = $func; }
        }
        $svc = new AgeCategories();
        $svc->register();
        $cb = $GLOBALS['filters']['wp_terms_checklist_args'][0];
        $args = $cb(['taxonomy' => 'age_category', 'checked_ontop' => true], 1);
        $this->assertFalse($args['checked_ontop']);
        $args = $cb(['taxonomy' => 'board', 'checked_ontop' => true], 1);
        $this->assertTrue($args['checked_ontop']);
    }
}
