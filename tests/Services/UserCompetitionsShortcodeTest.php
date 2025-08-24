<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

class UserCompetitionsShortcodeTest extends TestCase {
    /** @runInSeparateProcess */
    public function test_shortcode_outputs_competition_details(): void {
        require_once __DIR__ . '/stubs.php';
        $svc  = new Competitions();
        $html = $svc->user_competitions_shortcode();
        $this->assertStringContainsString( 'crm-competition-table', $html );
        $this->assertStringContainsString( 'competition-details/?competition_id=20', $html );
    }
}
