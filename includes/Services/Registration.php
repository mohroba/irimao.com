<?php
namespace IMAOCustom\Services;

use IMAOCustom\Logger;

class Registration {
    public function register(): void {
        add_action( 'woocommerce_created_customer', [ $this, 'set_national_id_and_wc_names' ], 10, 1 );
    }

    public function set_national_id_and_wc_names( $user_id ): void {
        $raw  = get_user_meta( $user_id, 'digits_form_data', true );
        $data = $raw ? maybe_unserialize( $raw ) : [];
        if ( ! is_array( $data ) ) {
            return;
        }

        $national_id = '';
        $first_name  = '';
        $last_name   = '';

        foreach ( $data as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['label'] ) || empty( $entry['meta_key'] ) ) {
                continue;
            }
            $label   = trim( $entry['label'] );
            $metaKey = $entry['meta_key'];
            $value   = get_user_meta( $user_id, $metaKey, true );

            if ( $label === 'کدملی' ) {
                $national_id = $value;
            }
            if ( $label === 'نام' || preg_match( '/^first_name_/', $metaKey ) ) {
                $first_name = $value;
            }
            if ( $label === 'نامخانوادگی' || preg_match( '/^last_name_/', $metaKey ) ) {
                $last_name = $value;
            }
        }

        if ( $national_id ) {
            update_user_meta( $user_id, 'national_id', sanitize_text_field( $national_id ) );
            global $wpdb;
            $wpdb->update(
                $wpdb->users,
                [ 'user_login' => sanitize_user( $national_id, true ) ],
                [ 'ID' => $user_id ]
            );
            $full_name = trim( $first_name . ' ' . $last_name );
            if ( $full_name ) {
                wp_update_user(
                    [
                        'ID'            => $user_id,
                        'display_name'  => $full_name,
                        'nickname'      => $full_name,
                        'user_nicename' => sanitize_title( $full_name ),
                    ]
                );
            }
            clean_user_cache( $user_id );
        }

        if ( $first_name ) {
            $san = sanitize_text_field( $first_name );
            update_user_meta( $user_id, 'billing_first_name', $san );
            update_user_meta( $user_id, 'first_name', $san );
            update_user_meta( $user_id, 'first_name_fa', $san );
        }
        if ( $last_name ) {
            $san = sanitize_text_field( $last_name );
            update_user_meta( $user_id, 'billing_last_name', $san );
            update_user_meta( $user_id, 'last_name', $san );
            update_user_meta( $user_id, 'last_name_fa', $san );
        }

        Logger::info( 'User registered', [ 'user_id' => $user_id ] );
    }
}
