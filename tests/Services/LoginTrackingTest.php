<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\LoginTracking;

if ( ! class_exists( 'WP_User' ) ) {
    class WP_User { public int $ID; }
}

class LoginTrackingTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['test_user_meta'] = [];
        if ( ! function_exists( 'update_user_meta' ) ) {
            function update_user_meta( $user_id, $key, $value ) {
                $GLOBALS['test_user_meta'][ $user_id ][ $key ] = $value;
                return true;
            }
        }
    }

    public function test_update_last_login_saves_meta(): void {
        $service = new LoginTrackingTestService();
        $user      = new WP_User();
        $user->ID  = 1;
        $service->update_last_login( 'user', $user );
        $this->assertSame( '2024-01-01 00:00:00', $GLOBALS['test_user_meta'][1]['last_login'] );
        $this->assertSame( '1402-10-11 00:00:00', $GLOBALS['test_user_meta'][1]['last_login_jalali'] );
    }
}

class LoginTrackingTestService extends LoginTracking {
    protected function get_gregorian_now(): string {
        return '2024-01-01 00:00:00';
    }
    protected function get_jalali_now(): string {
        return '1402-10-11 00:00:00';
    }
}
