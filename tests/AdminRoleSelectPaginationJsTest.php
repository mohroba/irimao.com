<?php

use PHPUnit\Framework\TestCase;

class AdminRoleSelectPaginationJsTest extends TestCase {
    private string $script;

    protected function setUp(): void {
        $this->script = (string) file_get_contents( dirname( __DIR__ ) . '/assets/js/crm-admin.js' );
    }

    public function test_role_selects_are_initialized_after_every_table_draw(): void {
        $this->assertStringContainsString( 'drawCallback', $this->script );
        $this->assertStringContainsString( 'initializeRoleSelects(this.api().table().body())', $this->script );
    }

    public function test_role_save_handler_is_delegated_and_reports_result(): void {
        $this->assertStringContainsString( "$(document).on('change', '#crm-prof-table .role-select'", $this->script );
        $this->assertStringContainsString( 'role-save-status', $this->script );
        $this->assertStringContainsString( 'نقش‌ها ذخیره شدند.', $this->script );
    }
}
