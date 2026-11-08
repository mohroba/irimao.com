<?php

namespace IMAOCustom\Services {
    function get_userdata( $id ) { return (object) [ 'ID' => 1, 'roles' => [ 'subscriber', 'coach' ] ]; }
    function get_user_meta( $id, $key, $single = true ) { return 'approved'; }
    function get_post( $id ) { return (object) [ 'post_type' => 'club_application', 'post_status' => 'publish', 'post_author' => 1 ]; }
    function check_admin_referer( ...$args ) {}
    function sanitize_text_field( $value ) { return strip_tags( $value ); }
    function wp_unslash( $value ) { return $value; }
    function absint( $value ) { return abs( (int) $value ); }
    function wp_insert_post( $data, $error = false ) {
        $GLOBALS['submitted_class'] = $data;
        $GLOBALS['test_post_meta'][100] = $data['meta_input'];
        return 100;
    }
    function is_wp_error( $value ) { return false; }
    function add_post_meta( ...$args ) {}
    function wc_get_account_endpoint_url( $endpoint ) { return '/my-account/' . $endpoint . '/'; }
    function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
    function wp_safe_redirect( $url ) { throw new \MonthlyClassRedirect( $url ); }
    function wp_die( $message, ...$args ) { throw new \RuntimeException( $message ); }
}

namespace {
    use IMAOCustom\Services\MonthlyClasses;
    use PHPUnit\Framework\TestCase;
    class MonthlyClassRedirect extends RuntimeException {}

    /** Run separately because WordPress service stubs are local to this test process. */
    final class MonthlyClassSubmissionTest extends TestCase {
        protected function setUp(): void {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [ 'class_start_time' => '10:00', 'class_end_time' => '11:00', 'class_fee' => '۱,۰۰۰,۰۰۰', 'class_club_id' => 5 ];
            unset( $GLOBALS['submitted_class'] );
        }

        public function test_coach_submission_without_name_is_saved_pending_and_redirects_to_list(): void {
            try { ( new MonthlyClasses() )->submit_class(); }
            catch ( MonthlyClassRedirect $redirect ) {
                $this->assertSame( '/my-account/monthly-class-submit/?class_submitted=1', $redirect->getMessage() );
            }
            $data = $GLOBALS['submitted_class'];
            $this->assertSame( 'pending', $data['post_status'] );
            $this->assertSame( '10:00–11:00', $data['post_title'] );
            $this->assertSame( '1000000', $data['meta_input']['_class_fee'] );
            $this->assertSame( 'toman', $data['meta_input']['_class_fee_unit'] );
            $this->assertSame( 1, $data['meta_input']['_class_coach_id'] );
        }

        public function test_end_before_start_cannot_create_a_class(): void {
            $_POST['class_end_time'] = '09:00';
            try { ( new MonthlyClasses() )->submit_class(); $this->fail( 'Invalid schedule accepted.' ); }
            catch ( RuntimeException $error ) { $this->assertArrayNotHasKey( 'submitted_class', $GLOBALS ); }
        }
    }
}
