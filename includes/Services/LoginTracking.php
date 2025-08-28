<?php
namespace IMAOCustom\Services;

use Morilog\Jalali\Jalalian;

class LoginTracking {
    public function register(): void {
        add_action( 'wp_login', [ $this, 'update_last_login' ], 10, 2 );
    }

    /**
     * Record the user's last login time in both Gregorian and Jalali calendars.
     *
     * @param string   $user_login User's login name.
     * @param \WP_User $user       WP_User instance.
     */
    public function update_last_login( string $user_login, \WP_User $user ): void {
        update_user_meta( $user->ID, 'last_login', $this->get_gregorian_now() );
        update_user_meta( $user->ID, 'last_login_jalali', $this->get_jalali_now() );
    }

    /**
     * Get current time in Gregorian calendar.
     */
    protected function get_gregorian_now(): string {
        return current_time( 'mysql' );
    }

    /**
     * Get current time in Jalali calendar.
     */
    protected function get_jalali_now(): string {
        return Jalalian::now()->format( 'Y-m-d H:i:s' );
    }
}
