<?php

namespace IMAOCustom\Services {
    function get_userdata( $id ) { return (object) [ 'ID' => 1, 'roles' => [ 'coach' ] ]; }
    function get_user_meta( $id, $key, $single = true ) { return 'approved'; }
    function get_post( $id ) { return (object) [ 'post_type' => 'club_application', 'post_status' => 'publish', 'post_author' => 1 ]; }
    function get_term( $id, $taxonomy ) { return $id === 10 ? (object) [ 'term_id' => 10, 'parent' => 0 ] : false; }
    function get_term_meta( $id, $key, $single = true ) { return $key === 'age_start' ? '10' : '18'; }
    function check_admin_referer( ...$args ) {}
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
    function wp_unslash( $value ) { return $value; }
    function absint( $value ) { return abs( (int) $value ); }
    function wp_insert_post( $data, $error = false ) { $GLOBALS['restricted_submission'] = $data; return 100; }
    function is_wp_error( $value ) { return false; }
    function add_post_meta( ...$args ) {}
    function wc_get_account_endpoint_url( $endpoint ) { return '/my-account/' . $endpoint . '/'; }
    function add_query_arg( $key, $value, $url ) { return $url . '?' . $key . '=' . $value; }
    function wp_safe_redirect( $url ) { throw new \LogicException( 'redirect' ); }
    function wp_die( $message, ...$args ) { throw new \RuntimeException( $message ); }
}

namespace IMAOCustom\Tests\Services {
    use IMAOCustom\Services\MonthlyClasses;
    use PHPUnit\Framework\TestCase;

    final class MonthlyClassRestrictedSubmissionTest extends TestCase {
        protected function setUp(): void {
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = [ 'class_start_time' => '10:00', 'class_end_time' => '11:00', 'class_fee' => '۱,۰۰۰,۰۰۰',
                'class_club_id' => 5, 'class_weekdays' => [ 'saturday', 'monday' ],
                'class_age_category' => '10', 'class_type' => 'semi-private' ];
            unset( $GLOBALS['restricted_submission'] );
        }

        public function test_submission_persists_valid_restrictions_pending_approval(): void {
            try { ( new MonthlyClasses() )->submit_class(); }
            catch ( \LogicException $redirect ) { $this->assertSame( 'redirect', $redirect->getMessage() ); }
            $data = $GLOBALS['restricted_submission'];
            $this->assertSame( 'pending', $data['post_status'] );
            $this->assertSame( 'no', $data['meta_input']['_class_approved'] );
            $this->assertSame( [ 'saturday', 'monday' ], $data['meta_input']['_class_weekdays'] );
            $this->assertSame( 10, $data['meta_input']['_class_age_category'] );
            $this->assertSame( 'semi-private', $data['meta_input']['_class_type'] );
        }

        public function test_missing_restrictions_reject_submission_before_inserting(): void {
            unset( $_POST['class_weekdays'] );
            try { ( new MonthlyClasses() )->submit_class(); $this->fail( 'Incomplete class accepted.' ); }
            catch ( \RuntimeException $error ) { $this->assertArrayNotHasKey( 'restricted_submission', $GLOBALS ); }
        }
    }
}
