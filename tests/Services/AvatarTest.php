<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Avatar;

class AvatarTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'get_user_by' ) ) {
            function get_user_by( $field, $value ) {
                if ( $field === 'id' && (int) $value === 1 ) {
                    return (object) [ 'ID' => 1 ];
                }
                if ( $field === 'email' && $value === 'user@example.com' ) {
                    return (object) [ 'ID' => 1 ];
                }
                return null;
            }
        }
        if ( ! function_exists( 'get_userdata' ) ) {
            function get_userdata( $user_id ) {
                return (object) [ 'ID' => $user_id ];
            }
        }
        if ( ! function_exists( 'get_user_meta' ) ) {
            function get_user_meta( $user_id, $key, $single = true ) {
                if ( $user_id === 1 && $key === 'personal_photo' ) {
                    return 'http://example.com/photo.jpg';
                }
                return '';
            }
        }
        if ( ! function_exists( 'esc_url_raw' ) ) {
            function esc_url_raw( $url ) { return $url; }
        }
    }

    public function test_filter_avatar_uses_personal_photo(): void {
        $service = new Avatar();
        $data    = $service->filter_avatar( [], 1 );
        $this->assertSame( 'http://example.com/photo.jpg', $data['url'] );
        $this->assertTrue( $data['found_avatar'] );
    }
}
