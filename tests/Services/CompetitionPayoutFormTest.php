<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Competitions;

if ( ! class_exists( 'WP_Post' ) ) {
    class WP_Post { public $ID; }
}

class CompetitionPayoutFormTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'wp_nonce_field' ) ) {
            function wp_nonce_field() {}
        }
        if ( ! function_exists( 'get_post_meta' ) ) {
            function get_post_meta( $id, $key, $single = true ) { return []; }
        }
        if ( ! function_exists( 'get_users' ) ) {
            function get_users() { return []; }
        }
        if ( ! function_exists( 'get_editable_roles' ) ) {
            function get_editable_roles() { return [ 'coach' => [ 'name' => 'Coach' ] ]; }
        }
        if ( ! function_exists( 'selected' ) ) {
            function selected( $a, $b, $echo = false ) { return $a === $b ? ' selected' : ''; }
        }
        if ( ! function_exists( 'checked' ) ) {
            function checked( $a, $b, $echo = false ) { return $a === $b ? ' checked' : ''; }
        }
        if ( ! function_exists( 'esc_attr' ) ) {
            function esc_attr( $s ) { return $s; }
        }
        if ( ! function_exists( 'esc_html' ) ) {
            function esc_html( $s ) { return $s; }
        }
    }

    public function test_render_forms_and_toggle(): void {
        $svc  = new Competitions();
        $post = new WP_Post();
        $post->ID = 1;
        ob_start();
        $svc->render_payouts_box( $post );
        $html = ob_get_clean();
        $this->assertStringContainsString('name="payout_mode"', $html);
        $this->assertStringContainsString('payout-user-form', $html);
        $this->assertStringContainsString('payout-role-form', $html);
    }
}
