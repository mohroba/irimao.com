<?php
namespace IMAOCustom\Services\Admin {
    function current_user_can() { return true; }
    function wp_die( $msg = '' ) { throw new \Exception( $msg ); }
    function get_users() { return [ (object) ['ID' => 1, 'display_name' => 'کاربر', 'user_email' => 'user@example.com'] ]; }
    function get_user_meta( $uid, $key, $single = true ) { return ''; }
    function admin_url( $url ) { return $url; }
    function esc_html( $text ) { return $text; }
    function esc_url( $text ) { return $text; }
}

namespace Tests\Services {
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Admin\UserManagement;

class UserManagementTest extends TestCase {
    public function test_basic_fields_contains_national_id(): void {
        $fields = UserManagement::basic_fields();
        $this->assertArrayHasKey( 'national_id', $fields );
        $this->assertArrayHasKey( 'billing_email', $fields );
    }

    public function test_basic_info_page_displays_email(): void {
        $um = new UserManagement();
        ob_start();
        $um->basic_info_page();
        $html = ob_get_clean();
        $this->assertStringContainsString('ایمیل', $html);
        $this->assertStringContainsString('user@example.com', $html);
    }
}
}
