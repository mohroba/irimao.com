<?php
namespace IMAOCustom\Services;

use IMAOCustom\Logger;

class Registration {
    private const REPAIR_OPTION = 'imao_display_names_repaired_v4';
    private const RETRY_HOOK = 'imao_sync_digits_registration';
    private bool $syncing = false;

    public function register(): void {
        add_action( 'user_register', [ $this, 'on_user_registered' ], 100, 1 );
        add_action( 'woocommerce_created_customer', [ $this, 'on_user_registered' ], 100, 1 );
        add_action( 'added_user_meta', [ $this, 'on_user_meta_changed' ], 100, 4 );
        add_action( 'updated_user_meta', [ $this, 'on_user_meta_changed' ], 100, 4 );
        add_action( self::RETRY_HOOK, [ $this, 'set_national_id_and_wc_names' ], 10, 1 );
        add_action( 'admin_init', [ $this, 'repair_numeric_display_names' ] );
    }

    public function on_user_registered( $user_id ): void {
        $this->set_national_id_and_wc_names( (int) $user_id );
        if ( function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::RETRY_HOOK, [ (int) $user_id ] ) ) {
            wp_schedule_single_event( time() + 10, self::RETRY_HOOK, [ (int) $user_id ] );
        }
    }

    public function on_user_meta_changed( $meta_id, $user_id, $meta_key, $meta_value ): void {
        if ( $meta_key === 'digits_form_data' || in_array( $meta_key, $this->name_meta_keys(), true ) ) {
            $this->set_national_id_and_wc_names( (int) $user_id );
        }
    }

    /**
     * Sync user data from the Digits registration form.
     *
     * @param int $user_id Newly created user ID.
     */
    public function set_national_id_and_wc_names( $user_id ): void {
        if ( $this->syncing || (int) $user_id < 1 ) {
            return;
        }
        $this->syncing = true;
        $data = $this->get_digits_data( (int) $user_id );
        $fields = $this->extract_fields( $data, $user_id );

        if ( $fields['national_id'] ) {
            $this->update_national_id( $user_id, $fields['national_id'] );
        }

        $this->update_names( $user_id, $fields['first_name'], $fields['last_name'] );
        $this->syncing = false;

        if ( $fields['national_id'] || $fields['first_name'] || $fields['last_name'] ) {
            Logger::info( 'Digits user data synchronized', [ 'user_id' => $user_id ] );
        }
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
        $out = [
            'national_id' => $this->first_meta_value( $user_id, [ 'national_id' ] ),
            'first_name'  => $this->first_meta_value( $user_id, [ 'first_name_fa', 'first_name', 'billing_first_name' ] ),
            'last_name'   => $this->first_meta_value( $user_id, [ 'last_name_fa', 'last_name', 'billing_last_name' ] ),
        ];
        foreach ( $entries as $entry ) {
            if ( ! is_array( $entry ) || empty( $entry['meta_key'] ) ) {
                continue;
            }
            $label   = trim( $entry['label'] ?? '' );
            $metaKey = (string) $entry['meta_key'];
            $value   = get_user_meta( $user_id, $metaKey, true );
            $normalized_label = preg_replace( '/[^\\p{L}]+/u', '', $label );
            $normalized_key = strtolower( preg_replace( '/[^a-z0-9]+/', '', $metaKey ) );

            if ( in_array( $normalized_label, [ 'کدملی', 'nationalid' ], true ) || str_contains( $normalized_key, 'nationalid' ) ) {
                $out['national_id'] = $value;
            } elseif ( in_array( $normalized_label, [ 'نام', 'نامفا', 'firstname', 'firstnamefa' ], true ) || str_contains( $normalized_key, 'firstname' ) ) {
                $out['first_name'] = $value;
            } elseif ( in_array( $normalized_label, [ 'نامخانوادگی', 'نامخانوادگیفا', 'lastname', 'lastnamefa' ], true ) || str_contains( $normalized_key, 'lastname' ) ) {
                $out['last_name'] = $value;
            }
        }

        return $out;
    }

    private function first_meta_value( int $user_id, array $keys ): string {
        foreach ( $keys as $key ) {
            $value = trim( (string) get_user_meta( $user_id, $key, true ) );
            if ( $value !== '' ) {
                return $value;
            }
        }
        return '';
    }

    private function name_meta_keys(): array {
        return [ 'first_name_fa', 'last_name_fa', 'first_name', 'last_name', 'billing_first_name', 'billing_last_name' ];
    }

    public function repair_numeric_display_names(): void {
        if ( get_option( self::REPAIR_OPTION, '0' ) === '1' || ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $users = get_users( [ 'fields' => [ 'ID', 'display_name' ], 'number' => -1 ] );
        $repaired = 0;
        foreach ( $users as $user ) {
            $compact = preg_replace( '/[^0-9۰-۹٠-٩]+/u', '', (string) $user->display_name );
            if ( $compact === '' || ! preg_match( '/^[0-9۰-۹٠-٩]{7,}$/u', $compact ) ) {
                continue;
            }
            $first = $this->first_meta_value( (int) $user->ID, [ 'first_name_fa', 'first_name', 'billing_first_name' ] );
            $last  = $this->first_meta_value( (int) $user->ID, [ 'last_name_fa', 'last_name', 'billing_last_name' ] );
            if ( $first !== '' || $last !== '' ) {
                $this->update_names( (int) $user->ID, $first, $last );
                $repaired++;
            }
        }
        update_option( self::REPAIR_OPTION, '1', false );
        Logger::info( 'Numeric display names repaired', [ 'count' => $repaired ] );
    }

    /**
     * Update national ID meta and user login.
     */
    private function update_national_id( int $user_id, string $national_id ): void {
        $national_id = $this->normalize_digits( $national_id );
        update_user_meta( $user_id, 'national_id', sanitize_text_field( $national_id ) );

        global $wpdb;
        $wpdb->update(
            $wpdb->users,
            [ 'user_login' => sanitize_user( $national_id, true ) ],
            [ 'ID' => $user_id ]
        );
    }

    /**
     * Convert Persian and Arabic digits to standard Latin digits.
     */
    private function normalize_digits( string $value ): string {
        $persian = [ '۰','۱','۲','۳','۴','۵','۶','۷','۸','۹' ];
        $arabic  = [ '٠','١','٢','٣','٤','٥','٦','٧','٨','٩' ];
        $latin   = [ '0','1','2','3','4','5','6','7','8','9' ];
        return str_replace( array_merge( $persian, $arabic ), array_merge( $latin, $latin ), $value );
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
