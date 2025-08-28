<?php
use PHPUnit\Framework\TestCase;

class ClubAdminJsTest extends TestCase {
    public function test_license_section_is_conditional(): void {
        $content = file_get_contents(__DIR__ . '/../../assets/js/club-admin.js');
        $this->assertStringContainsString('d.lic ?', $content);
    }
}
