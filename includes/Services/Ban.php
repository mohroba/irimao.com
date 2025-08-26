<?php
namespace IMAOCustom\Services;

class Ban {
    public function register(): void {
        add_filter( 'wp_authenticate_user', [ $this, 'block_login' ], 10, 2 );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'block_purchase' ], 10, 3 );
        add_action( 'woocommerce_checkout_process', [ $this, 'stop_checkout' ] );
    }

    /**
     * Prevent banned users from logging in.
     *
     * @param mixed  $user     WP_User or WP_Error.
     * @param string $password Raw password.
     *
     * @return mixed
     */
    public function block_login( $user, string $password ) {
        if ( is_wp_error( $user ) ) {
            return $user;
        }
        if ( get_user_meta( $user->ID, 'imao_banned', true ) ) {
            return new \WP_Error( 'banned_user', __( 'حساب شما مسدود است.', 'imao-custom-plugin' ) );
        }
        return $user;
    }

    /**
     * Stop banned users from adding items to cart.
     */
    public function block_purchase( bool $passed, int $product_id, int $quantity ): bool {
        if ( $passed && is_user_logged_in() && get_user_meta( get_current_user_id(), 'imao_banned', true ) ) {
            wc_add_notice( __( 'حساب شما مسدود است.', 'imao-custom-plugin' ), 'error' );
            return false;
        }
        return $passed;
    }

    /**
     * Prevent checkout for banned users who already have items.
     */
    public function stop_checkout(): void {
        if ( is_user_logged_in() && get_user_meta( get_current_user_id(), 'imao_banned', true ) ) {
            wc_add_notice( __( 'حساب شما مسدود است.', 'imao-custom-plugin' ), 'error' );
        }
    }
}
