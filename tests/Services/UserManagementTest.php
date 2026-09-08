<?php
namespace IMAOCustom\Services\Admin {
    function current_user_can() { return true; }
    function wp_die( $msg = '' ) { throw new \Exception( $msg ); }
    function get_users() { return [ (object) ['ID' => 1, 'display_name' => 'کاربر', 'user_email' => 'user@example.com'] ]; }
    function admin_url( $url = '' ) { return $url ?: '/wp-admin/'; }
    function esc_html( $text ) { return $text; }
    function esc_url( $text ) { return $text; }
    function wp_create_nonce( $action ) { return 'valid'; }
    function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=valid'; }
    function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid'; }
    function wp_set_current_user( $id ) { $GLOBALS['current_user'] = $id; }
    function wp_set_auth_cookie( $id ) { $GLOBALS['auth_user'] = $id; }
    function wp_safe_redirect( $url ) { $GLOBALS['redirected_to'] = $url; throw new \Exception( 'redirect' ); }
    function check_ajax_referer() { return true; }
    function wp_send_json_success() { return true; }
    function wp_send_json_error() { return false; }
    function update_user_meta( $uid, $key, $val ) { $GLOBALS['user_meta'][$uid][$key] = $val; }
    function delete_user_meta( $uid, $key ) { unset( $GLOBALS['user_meta'][$uid][$key] ); }
    function get_user_meta( $uid, $key, $single = true ) { return $GLOBALS['user_meta'][$uid][$key] ?? ''; }
    function sanitize_text_field( $str ) { return $str; }
    function sanitize_key( $str ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $str ) ); }
    function esc_url_raw( $url ) { return $url; }
    function wp_roles() { return (object) [ 'roles' => [ 'editor' => [], 'subscriber' => [] ] ]; }
    class DummyUser {
        public array $roles = [ 'subscriber' ];
        public function add_role( $role ) { $this->roles[] = $role; $GLOBALS['roles_added'][] = $role; }
        public function remove_role( $role ) { $this->roles = array_values( array_diff( $this->roles, [ $role ] ) ); $GLOBALS['roles_removed'][] = $role; }
    }
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
        $this->assertStringContainsString('ورود', $html);
    }

    public function test_login_as_user_sets_auth_cookie_and_redirects(): void {
        $um = new UserManagement();
        $_GET = [ 'user_id' => 1, '_wpnonce' => 'valid' ];
        try {
            $um->login_as_user();
        } catch ( \Exception $e ) {
            // swallow redirect exception
        }
        $this->assertSame( 1, $GLOBALS['current_user'] );
        $this->assertSame( 1, $GLOBALS['auth_user'] );
        $this->assertSame( '/wp-admin/', $GLOBALS['redirected_to'] );
    }

    public function test_change_status_updates_multiple_roles(): void {
        $um = new UserManagement();
        $_POST = [ 'user' => 1, 'action_type' => 'approve', 'roles' => [ 'editor', 'subscriber' ] ];
        $GLOBALS['roles_added'] = [];
        $GLOBALS['roles_removed'] = [];
        $um->change_status();
        $this->assertSame( [ 'editor' ], $GLOBALS['roles_added'] );
        $this->assertSame( [], $GLOBALS['roles_removed'] );
    }

    public function test_role_sync_removes_only_unselected_roles(): void {
        $user = new \IMAOCustom\Services\Admin\DummyUser();
        $user->roles = [ 'subscriber', 'editor' ];
        $GLOBALS['roles_added'] = [];
        $GLOBALS['roles_removed'] = [];
        ( new UserManagement() )->sync_user_roles( $user, [ 'editor' ] );
        $this->assertSame( [ 'subscriber' ], $GLOBALS['roles_removed'] );
        $this->assertSame( [ 'editor' ], $user->roles );
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
