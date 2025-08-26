<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Ban;

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        public function __construct( public string $code = '', public string $message = '' ) {}
    }
}
if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}
if ( ! function_exists( 'get_user_meta' ) ) {
    function get_user_meta( $uid, $key, $single = false ) { return $GLOBALS['ban_meta'][$uid][$key] ?? ''; }
}
if ( ! function_exists( 'wc_add_notice' ) ) {
    function wc_add_notice( $msg, $type = '' ) { $GLOBALS['ban_notices'][] = [$msg, $type]; }
}
if ( ! function_exists( 'is_user_logged_in' ) ) {
    function is_user_logged_in() { return $GLOBALS['ban_logged_in'] ?? false; }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
    function get_current_user_id() { return $GLOBALS['ban_current_user'] ?? 0; }
}
if ( ! function_exists( '__' ) ) {
    function __( $str ) { return $str; }
}

class BanTest extends TestCase {
    protected function setUp(): void {
        $GLOBALS['ban_meta']        = [];
        $GLOBALS['ban_notices']     = [];
        $GLOBALS['ban_logged_in']   = true;
        $GLOBALS['ban_current_user']= 1;
    }

    public function test_block_login_returns_error_when_banned(): void {
        $GLOBALS['ban_meta'][1]['imao_banned'] = 1;
        $svc   = new Ban();
        $user  = (object) ['ID' => 1];
        $res   = $svc->block_login( $user, 'pw' );
        $this->assertInstanceOf( WP_Error::class, $res );
    }

    public function test_block_purchase_adds_notice(): void {
        $GLOBALS['ban_meta'][1]['imao_banned'] = 1;
        $svc = new Ban();
        $out = $svc->block_purchase( true, 10, 1 );
        $this->assertFalse( $out );
        $this->assertNotEmpty( $GLOBALS['ban_notices'] );
    }
}
