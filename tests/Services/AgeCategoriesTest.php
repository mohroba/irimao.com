<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\AgeCategories;

class AgeCategoriesTest extends TestCase
{
    /** @runInSeparateProcess */
    public function test_register_hooks(): void
    {
        if ( ! function_exists( 'add_action' ) ) {
            function add_action( $hook, $func ) { $GLOBALS['actions'][ $hook ][] = $func; }
        }
        $svc = new AgeCategories();
        $svc->register();
        $this->assertArrayHasKey( 'age_category_add_form_fields', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'age_category_edit_form_fields', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'created_age_category', $GLOBALS['actions'] );
        $this->assertArrayHasKey( 'edited_age_category', $GLOBALS['actions'] );
    }
}
