<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\SelfDeclarations;

class SelfDeclarationsTest extends TestCase {
    public function test_register_hooks_delete_action(): void {
        if ( ! function_exists( 'add_action' ) || ! function_exists( 'has_action' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $service = new SelfDeclarations();
        $service->register();
        $this->assertNotFalse( has_action( 'wp_ajax_crm_selfdec_delete', [ $service, 'ajax_delete' ] ) );
    }
}
