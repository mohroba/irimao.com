<?php
namespace IMAOCustom\Services\Admin {
    function current_user_can() { return true; }
    function wp_die( $msg = '' ) { throw new \Exception( $msg ); }
    function get_users() { return [ (object) ['ID' => 1, 'display_name' => 'کاربر', 'user_email' => 'user@example.com'] ]; }
    function admin_url( $url ) { return $url; }
    function esc_html( $text ) { return $text; }
    function esc_url( $text ) { return $text; }
    function check_ajax_referer() { return true; }
    function wp_send_json_success() { return true; }
    function wp_send_json_error() { return false; }
    function update_user_meta( $uid, $key, $val ) { $GLOBALS['user_meta'][$uid][$key] = $val; }
    function delete_user_meta( $uid, $key ) { unset( $GLOBALS['user_meta'][$uid][$key] ); }
    function get_user_meta( $uid, $key, $single = true ) { return $GLOBALS['user_meta'][$uid][$key] ?? ''; }
    function sanitize_text_field( $str ) { return $str; }
    function esc_url_raw( $url ) { return $url; }
    function wp_roles() { return (object) [ 'roles' => [ 'editor' => [], 'subscriber' => [] ] ]; }
    class DummyUser { public function set_role( $r ) { $GLOBALS['last_role_set'] = $r; } }
    function get_userdata( $uid ) { return new DummyUser(); }
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

    public function test_change_status_updates_role(): void {
        $um = new UserManagement();
        $_POST = [ 'user' => 1, 'action_type' => 'approve', 'role' => 'editor' ];
        $GLOBALS['last_role_set'] = '';
        $um->change_status();
        $this->assertSame( 'editor', $GLOBALS['last_role_set'] );
    }

    public function test_toggle_ban_sets_meta(): void {
        $um = new UserManagement();
        $_POST = [ 'user' => 1, 'ban_action' => 'ban' ];
        $um->toggle_ban();
        $this->assertSame( 1, $GLOBALS['user_meta'][1]['imao_banned'] );
        $_POST = [ 'user' => 1, 'ban_action' => 'unban' ];
        $um->toggle_ban();
        $this->assertArrayNotHasKey( 'imao_banned', $GLOBALS['user_meta'][1] );
    }
}
}
