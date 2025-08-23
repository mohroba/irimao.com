<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class CompetitionShortcodesTest extends TestCase {
    public function test_competitions_list_has_container_and_header(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $svc = new Competitions();
        $output = $svc->competitions_list_shortcode();
        $this->assertStringContainsString( 'class="sd-container"', $output );
        $this->assertStringContainsString( 'class="sd-header"', $output );
    }

    public function test_competition_details_uses_course_design(): void {
        if ( ! class_exists( 'WP_Query' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }
        $svc = new Competitions();
        $output = $svc->competition_details_shortcode();
        $this->assertStringContainsString( 'class="crm-single-course"', $output );
    }
}
