<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\CheckoutPrefill;

class CheckoutPrefillTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['prefill_filters']   = [];
        $GLOBALS['prefill_logged_in'] = true;
        $GLOBALS['prefill_user_id']   = 1;
        $GLOBALS['prefill_meta']      = [];
        $GLOBALS['prefill_userdata']  = [];

        if ( ! function_exists( 'is_user_logged_in' ) ) {
            function is_user_logged_in() {
                return $GLOBALS['prefill_logged_in'] ?? false;
            }
        }
        if ( ! function_exists( 'get_current_user_id' ) ) {
            function get_current_user_id() {
                return $GLOBALS['prefill_user_id'] ?? 0;
            }
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $user_id, $key, $single = true ) {
                return $GLOBALS['prefill_meta'][ $user_id ][ $key ] ?? '';
            }
        }
        if ( ! function_exists( 'get_userdata' ) ) {
            function get_userdata( $user_id ) {
                return $GLOBALS['prefill_userdata'][ $user_id ] ?? null;
            }
        }
    }

    public function test_prefill_uses_basic_info_meta(): void {
        $GLOBALS['prefill_user_id'] = 42;
        $GLOBALS['prefill_meta'][42] = [
            'first_name_fa'      => 'علی',
            'last_name_fa'       => 'رضا',
            'residence_address'  => 'تهران، خیابان مثال ۱۲۳',
            'residence_province' => 'IR-08',
            'residence_city'     => 'تهران',
            'postal_code'        => '1234567890',
            'billing_phone'      => '09123456789',
            'billing_email'      => 'stored@example.com',
        ];
        $GLOBALS['prefill_userdata'][42] = (object) [ 'user_email' => 'user@example.com' ];

        $service = new CheckoutPrefill();

        $this->assertSame( 'علی', $service->prefill_checkout_value( '', 'billing_first_name' ) );
        $this->assertSame( 'user@example.com', $service->prefill_checkout_value( '', 'billing_email' ) );
        $this->assertSame( 'IR-08', $service->prefill_checkout_value( '', 'billing_state' ) );
        $this->assertSame( 'تهران', $service->prefill_checkout_value( '', 'shipping_city' ) );
        $this->assertSame( '09123456789', $service->prefill_checkout_value( '', 'shipping_phone' ) );
        $this->assertSame( 'تهران، خیابان مثال ۱۲۳', $service->prefill_checkout_value( '', 'billing_address_1' ) );
    }

    public function test_prefill_does_not_override_existing_value(): void {
        $GLOBALS['prefill_meta'][1]['first_name_fa'] = 'علی';
        $service = new CheckoutPrefill();

        $this->assertSame( 'Manual', $service->prefill_checkout_value( 'Manual', 'billing_first_name' ) );
    }

    public function test_prefill_skips_when_user_not_logged_in(): void {
        $GLOBALS['prefill_logged_in'] = false;
        $service = new CheckoutPrefill();

        $this->assertSame( '', $service->prefill_checkout_value( '', 'billing_first_name' ) );
    }
}
