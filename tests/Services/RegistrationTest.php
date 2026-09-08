<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Registration;

class RegistrationTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'get_user_meta' ) ) {
            $GLOBALS['test_user_meta'] = [];
            function get_user_meta( $user_id, $key, $single = true ) {
                return $GLOBALS['test_user_meta'][$user_id][$key] ?? '';
            }
            function update_user_meta( $user_id, $key, $value ) {
                $GLOBALS['test_user_meta'][$user_id][$key] = $value;
            }
            function wp_update_user( $data ) {
                $GLOBALS['updated_user'] = $data;
                return true;
            }
            function clean_user_cache( $user_id ) {
                $GLOBALS['clean_cache'] = $user_id;
            }
        }
        if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( $value ) { return is_string( $value ) ? trim( $value ) : $value; } }
        if ( ! function_exists( 'sanitize_user' ) ) { function sanitize_user( $value, $strict = false ) { return preg_replace( '/[^a-zA-Z0-9_]/', '', $value ); } }
        if ( ! function_exists( 'sanitize_title' ) ) { function sanitize_title( $value ) { return strtolower( str_replace( ' ', '-', $value ) ); } }
        if ( ! function_exists( 'wp_update_user' ) ) { function wp_update_user( $data ) { $GLOBALS['updated_user'] = $data; return true; } }
        if ( ! function_exists( 'clean_user_cache' ) ) { function clean_user_cache( $user_id ) { $GLOBALS['clean_cache'] = $user_id; } }
        if ( ! function_exists( 'maybe_unserialize' ) ) {
            function maybe_unserialize( $data ) {
                if ( ! is_string( $data ) || ! preg_match( '/^[aObisCdN]:/', $data ) ) { return $data; }
                return unserialize( $data );
            }
        }

        $GLOBALS['wpdb'] = new class {
            public array $updated = [];
            public $users = 'users';
            public function update( $table, $data, $where ) {
                $this->updated = compact( 'table', 'data', 'where' );
                return true;
            }
        };
    }

    public function test_set_national_id_and_wc_names(): void {
        if ( ! class_exists( Registration::class ) ) {
            $this->markTestSkipped( 'Plugin not loaded.' );
        }

        $user_id = 1;
        $GLOBALS['test_user_meta'][$user_id] = [
            'digits_form_data' => serialize([
                [ 'label' => 'کدملی', 'meta_key' => 'field_nat' ],
                [ 'label' => 'نام', 'meta_key' => 'field_fname' ],
                [ 'label' => 'نامخانوادگی', 'meta_key' => 'field_lname' ],
            ]),
            'field_nat'   => '1234567890',
            'field_fname' => 'Ali',
            'field_lname' => 'Reza',
        ];

        $reg = new Registration();
        $reg->set_national_id_and_wc_names( $user_id );

        $this->assertSame( '1234567890', get_user_meta( $user_id, 'national_id', true ) );
        $this->assertSame( 'Ali', get_user_meta( $user_id, 'first_name_fa', true ) );
        $this->assertSame( 'Reza', get_user_meta( $user_id, 'last_name_fa', true ) );
        $this->assertSame( '1234567890', $GLOBALS['wpdb']->updated['data']['user_login'] );
        $this->assertSame( 'users', $GLOBALS['wpdb']->updated['table'] );
        $this->assertSame( 'Ali Reza', $GLOBALS['updated_user']['display_name'] );
    }

    public function test_converts_persian_digits(): void {
        if ( ! class_exists( Registration::class ) ) {
            $this->markTestSkipped( 'Plugin not loaded.' );
        }
        $user_id = 2;
        $GLOBALS['test_user_meta'][$user_id] = [
            'digits_form_data' => serialize([
                [ 'label' => 'کدملی', 'meta_key' => 'field_nat' ],
            ]),
            'field_nat' => '۱۲۳۴۵۶۷۸۹۰',
        ];
        $reg = new Registration();
        $reg->set_national_id_and_wc_names( $user_id );
        $this->assertSame( '1234567890', get_user_meta( $user_id, 'national_id', true ) );
        $this->assertSame( '1234567890', $GLOBALS['wpdb']->updated['data']['user_login'] );
    }

    public function test_uses_profile_name_meta_when_digits_payload_is_late(): void {
        $user_id = 3;
        $GLOBALS['test_user_meta'][$user_id] = [
            'first_name_fa' => 'فاطمه',
            'last_name_fa'  => 'غرابی',
        ];

        ( new Registration() )->set_national_id_and_wc_names( $user_id );

        $this->assertSame( 'فاطمه غرابی', $GLOBALS['updated_user']['display_name'] );
        $this->assertSame( 'فاطمه', get_user_meta( $user_id, 'billing_first_name', true ) );
        $this->assertSame( 'غرابی', get_user_meta( $user_id, 'billing_last_name', true ) );
    }
}
