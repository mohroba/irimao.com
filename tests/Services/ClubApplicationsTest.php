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
}
