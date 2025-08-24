<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\ClubApplications;

class ClubApplicationsTest extends TestCase {
    public function test_register_cpt_skips_without_wp(): void {
        if ( ! function_exists( 'register_post_type' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $service = new ClubApplications();
        $service->register_cpt();
        $this->assertTrue( post_type_exists( 'club_application' ) );
    }

    public function test_register_hooks_delete_action(): void {
        if ( ! function_exists( 'add_action' ) || ! function_exists( 'has_action' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $service = new ClubApplications();
        $service->register();
        $this->assertNotFalse( has_action( 'wp_ajax_crm_club_delete', [ $service, 'ajax_delete' ] ) );
    }
}
