<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Admin\UserManagement;

class UserManagementTest extends TestCase {
    public function test_basic_fields_contains_national_id(): void {
        $fields = UserManagement::basic_fields();
        $this->assertArrayHasKey( 'national_id', $fields );
    }
}
