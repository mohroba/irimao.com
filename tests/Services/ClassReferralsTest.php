<?php
namespace IMAOCustom\Services {
    function current_user_can( $cap, ...$args ) { return $GLOBALS['referral_caps'][ $cap ] ?? true; }
    function wp_verify_nonce( $nonce, $action ) { return $nonce === 'valid' && $action === 'imao_class_referrer_1'; }
    function wp_unslash( $value ) { return stripslashes( $value ); }
    function get_userdata( $id ) { return in_array( $id, [ 1, 2, 3 ], true ) ? (object) [ 'ID' => $id ] : false; }
    function add_user_meta( $id, $key, $value ) { $GLOBALS['referral_audit'][] = $value; return 1; }
}

namespace Tests\Services {
    use IMAOCustom\Services\ClassReferrals;
    use PHPUnit\Framework\TestCase;

    class ClassReferralsTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['test_user_meta'][1]['referrer_id'] = 2;
            $GLOBALS['referral_caps'] = [];
            $GLOBALS['referral_audit'] = [];
            $_POST = [ 'referrer_id' => '3', 'imao_class_referrer_nonce' => 'valid' ];
        }

        protected function tearDown(): void {
            $_POST = [];
            unset( $GLOBALS['referral_caps'], $GLOBALS['referral_audit'], $GLOBALS['test_user_meta'][1] );
        }

        public function test_change_is_saved_and_audited_once(): void {
            $service = new ClassReferrals();
            $service->save_profile_field( 1 );
            $service->save_profile_field( 1 );
            $this->assertSame( 3, $GLOBALS['test_user_meta'][1]['referrer_id'] );
            $this->assertSame( [ [ 'previous' => 2, 'new' => 3, 'actor' => 1, 'time' => 'now' ] ], $GLOBALS['referral_audit'] );
        }

        public function test_empty_field_clears_with_audit(): void {
            $_POST['referrer_id'] = '';
            ( new ClassReferrals() )->save_profile_field( 1 );
            $this->assertSame( 0, $GLOBALS['test_user_meta'][1]['referrer_id'] );
            $this->assertSame( 0, $GLOBALS['referral_audit'][0]['new'] );
        }

        public function test_invalid_referrers_cannot_be_saved(): void {
            foreach ( [ '1', '999', '-2', '0', '2.5', '3junk', [ '3' ] ] as $value ) {
                $_POST['referrer_id'] = $value;
                ( new ClassReferrals() )->save_profile_field( 1 );
                $this->assertSame( 2, $GLOBALS['test_user_meta'][1]['referrer_id'] );
                $this->assertSame( [], $GLOBALS['referral_audit'] );
            }
        }

        public function test_capabilities_and_nonce_are_required(): void {
            foreach ( [ 'manage_options', 'edit_user' ] as $cap ) {
                $GLOBALS['referral_caps'] = [ $cap => false ];
                ( new ClassReferrals() )->save_profile_field( 1 );
                $this->assertSame( 2, $GLOBALS['test_user_meta'][1]['referrer_id'] );
            }
            $GLOBALS['referral_caps'] = [];
            foreach ( [ '', 'invalid', [ 'valid' ] ] as $nonce ) {
                $_POST['imao_class_referrer_nonce'] = $nonce;
                ( new ClassReferrals() )->save_profile_field( 1 );
                $this->assertSame( 2, $GLOBALS['test_user_meta'][1]['referrer_id'] );
            }
            $this->assertSame( [], $GLOBALS['referral_audit'] );
        }

        public function test_absent_field_preserves_referral(): void {
            unset( $_POST['referrer_id'] );
            ( new ClassReferrals() )->save_profile_field( 1 );
            $this->assertSame( 2, $GLOBALS['test_user_meta'][1]['referrer_id'] );
            $this->assertSame( [], $GLOBALS['referral_audit'] );
        }
    }
}
