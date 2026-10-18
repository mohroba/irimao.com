<?php
namespace IMAOCustom\Services;

class ClassReferrals {
    public const AUDIT_META_KEY = 'referrer_id_audit';

    public function register(): void {
        add_action( 'show_user_profile', [ $this, 'render_profile_field' ] );
        add_action( 'edit_user_profile', [ $this, 'render_profile_field' ] );
        add_action( 'user_profile_update_errors', [ $this, 'validate_profile_field' ], 10, 3 );
        add_action( 'personal_options_update', [ $this, 'save_profile_field' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_profile_field' ] );
    }

    private function can_edit( int $user_id ): bool {
        return $user_id > 0 && current_user_can( 'manage_options' ) && current_user_can( 'edit_user', $user_id );
    }

    public function render_profile_field( $user ): void {
        $user_id = (int) $user->ID;
        if ( ! $this->can_edit( $user_id ) ) {
            return;
        }
        echo '<h2>معرف کلاس‌ها</h2><table class="form-table"><tr><th><label for="imao_referrer_id">معرف</label></th><td>';
        wp_nonce_field( 'imao_class_referrer_' . $user_id, 'imao_class_referrer_nonce' );
        echo '<input type="number" min="1" step="1" id="imao_referrer_id" name="referrer_id" value="'
            . esc_attr( get_user_meta( $user_id, 'referrer_id', true ) ) . '">';
        echo '<p class="description">شناسه کاربر معرف را وارد کنید؛ برای حذف، خالی بگذارید.</p></td></tr></table>';
    }

    /** Return null for an absent field, false for an invalid request, or a user ID (zero clears it). */
    private function requested_referrer( int $user_id ) {
        if ( ! array_key_exists( 'referrer_id', $_POST ) ) {
            return null;
        }
        $nonce = $_POST['imao_class_referrer_nonce'] ?? '';
        if ( ! $this->can_edit( $user_id ) || ! is_string( $nonce )
            || ! wp_verify_nonce( wp_unslash( $nonce ), 'imao_class_referrer_' . $user_id ) ) {
            return false;
        }
        $raw = $_POST['referrer_id'];
        if ( ! is_string( $raw ) ) {
            return false;
        }
        $raw = trim( wp_unslash( $raw ) );
        if ( $raw === '' ) {
            return 0;
        }
        $referrer_id = filter_var( $raw, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
        if ( $referrer_id === false || $referrer_id === $user_id || ! get_userdata( $referrer_id ) ) {
            return false;
        }
        return $referrer_id;
    }

    public function validate_profile_field( $errors, $update, $user ): void {
        if ( $update && $this->requested_referrer( (int) $user->ID ) === false ) {
            $errors->add( 'imao_invalid_referrer', 'معرف نامعتبر است یا اجازه تغییر آن را ندارید.' );
        }
    }

    public function save_profile_field( int $user_id ): void {
        $referrer_id = $this->requested_referrer( $user_id );
        if ( $referrer_id === null || $referrer_id === false || ! get_userdata( $user_id ) ) {
            return;
        }
        $previous = (int) get_user_meta( $user_id, 'referrer_id', true );
        if ( $previous === $referrer_id ) {
            return;
        }
        if ( update_user_meta( $user_id, 'referrer_id', $referrer_id ) === false ) {
            return;
        }
        add_user_meta( $user_id, self::AUDIT_META_KEY, [
            'previous' => $previous,
            'new'      => $referrer_id,
            'actor'    => get_current_user_id(),
            'time'     => current_time( 'mysql', true ),
        ] );
    }
}
