<?php
namespace IMAOCustom\Services;

use IMAOCustom\Logger;

class Registration {
    public function register(): void {
        add_action( 'woocommerce_created_customer', [ $this, 'set_national_id_and_wc_names' ], 10, 1 );
    }

    /**
     * Sync user data from the Digits registration form.
     *
     * @param int $user_id Newly created user ID.
     */
    public function set_national_id_and_wc_names( $user_id ): void {
        $data = $this->get_digits_data( (int) $user_id );
        if ( ! $data ) {
            return;
        }

        $fields = $this->extract_fields( $data, $user_id );

        if ( $fields['national_id'] ) {
            $this->update_national_id( $user_id, $fields['national_id'] );
        }

        $this->update_names( $user_id, $fields['first_name'], $fields['last_name'] );

        Logger::info( 'User registered', [ 'user_id' => $user_id ] );
    }

    /**
     * Get and unserialize Digits form data for a user.
     */
    private function get_digits_data( int $user_id ): array {
        $raw  = get_user_meta( $user_id, 'digits_form_data', true );
        $data = $raw ? maybe_unserialize( $raw ) : [];
        return is_array( $data ) ? $data : [];
    }

    /**
     * Extract relevant fields from Digits entries.
     */
    private function extract_fields( array $entries, int $user_id ): array {
        $out = [ 'national_id' => '', 'first_name' => '', 'last_name' => '' ];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['meta_key'] ) ) {
                continue;
            }
            $label   = trim( $entry['label'] ?? '' );
            $metaKey = $entry['meta_key'];
            $value   = get_user_meta( $user_id, $metaKey, true );

            if ( $label === 'کدملی' ) {
                $out['national_id'] = $value;
            } elseif ( $label === 'نام' || preg_match( '/^first_name_/', $metaKey ) ) {
                $out['first_name'] = $value;
            } elseif ( $label === 'نامخانوادگی' || preg_match( '/^last_name_/', $metaKey ) ) {
                $out['last_name'] = $value;
            }
        }

        return $out;
    }

    /**
     * Update national ID meta and user login.
     */
    private function update_national_id( int $user_id, string $national_id ): void {
        update_user_meta( $user_id, 'national_id', sanitize_text_field( $national_id ) );

        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            [ 'user_login' => sanitize_user( $national_id, true ) ],
            [ 'ID' => $user_id ]
        );
    }

    /**
     * Update user names and related meta fields.
     */
    private function update_names( int $user_id, string $first_name, string $last_name ): void {
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

        foreach ( [ 'first_name' => $first_name, 'last_name' => $last_name ] as $key => $value ) {
            if ( ! $value ) {
                continue;
            }
            $san = sanitize_text_field( $value );
            update_user_meta( $user_id, "billing_{$key}", $san );
            update_user_meta( $user_id, $key, $san );
            update_user_meta( $user_id, "{$key}_fa", $san );
        }

        if ( $full_name || $first_name || $last_name ) {
            clean_user_cache( $user_id );
        }
    }
}
