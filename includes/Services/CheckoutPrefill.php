<?php

namespace IMAOCustom\Services;

class CheckoutPrefill {
    public function register(): void {
        if ( function_exists( 'add_filter' ) ) {
            add_filter( 'woocommerce_checkout_get_value', [ $this, 'prefill_checkout_value' ], 10, 2 );
        }
    }

    /**
     * Populate checkout fields with values saved in the basic info form.
     *
     * @param mixed  $value Existing value from WooCommerce or POST data.
     * @param string $key   Field key being rendered.
     * @return mixed
     */
    public function prefill_checkout_value( $value, string $key ) {
        if ( $this->has_value( $value ) ) {
            return $value;
        }
        if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
            return $value;
        }
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return $value;
        }
        $user_id = (int) get_current_user_id();
        if ( $user_id <= 0 ) {
            return $value;
        }

        $sources = $this->field_sources()[ $key ] ?? null;
        if ( ! $sources ) {
            return $value;
        }

        foreach ( $sources as $source ) {
            $resolved = $source === 'user_email'
                ? $this->get_user_email( $user_id )
                : $this->get_user_meta_value( $user_id, $source );

            if ( $resolved !== null ) {
                return $resolved;
            }
        }

        return $value;
    }

    /**
     * Determine whether WooCommerce already has a value that should be kept.
     */
    private function has_value( $value ): bool {
        if ( is_array( $value ) ) {
            return ! empty( $value );
        }
        if ( is_object( $value ) ) {
            return true;
        }
        return (string) $value !== '';
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function field_sources(): array {
        return [
            'billing_first_name' => [ 'first_name_fa', 'first_name' ],
            'billing_last_name'  => [ 'last_name_fa', 'last_name' ],
            'billing_phone'      => [ 'billing_phone' ],
            'billing_email'      => [ 'user_email', 'billing_email' ],
            'billing_state'      => [ 'residence_province' ],
            'billing_city'       => [ 'residence_city' ],
            'billing_postcode'   => [ 'postal_code' ],
            'billing_address_1'  => [ 'residence_address' ],
            'shipping_first_name'=> [ 'first_name_fa', 'first_name' ],
            'shipping_last_name' => [ 'last_name_fa', 'last_name' ],
            'shipping_state'     => [ 'residence_province' ],
            'shipping_city'      => [ 'residence_city' ],
            'shipping_postcode'  => [ 'postal_code' ],
            'shipping_address_1' => [ 'residence_address' ],
            'shipping_phone'     => [ 'billing_phone' ],
        ];
    }

    private function get_user_email( int $user_id ): ?string {
        if ( function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
            if ( $user && ! empty( $user->user_email ) ) {
                return (string) $user->user_email;
            }
        }
        return $this->get_user_meta_value( $user_id, 'billing_email' );
    }

    private function get_user_meta_value( int $user_id, string $key ): ?string {
        if ( ! function_exists( 'get_user_meta' ) ) {
            return null;
        }
        $raw = get_user_meta( $user_id, $key, true );
        if ( is_string( $raw ) ) {
            return $raw === '' ? null : $raw;
        }
        if ( is_numeric( $raw ) ) {
            return (string) $raw;
        }
        return null;
    }
}
