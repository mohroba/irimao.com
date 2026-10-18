<?php

namespace IMAOCustom\Services {
    function get_post( $id ) { return $GLOBALS['class_eligibility_posts'][$id] ?? null; }
    function get_post_meta( $id, $key, $single = true ) { return $GLOBALS['class_eligibility_meta'][$id][$key] ?? ''; }
    function get_user_meta( $id, $key, $single = true ) { return $GLOBALS['class_eligibility_users'][$id][$key] ?? ''; }
    function get_userdata( $id ) { return $GLOBALS['class_eligibility_accounts'][$id] ?? false; }
}

namespace IMAOCustom\Tests\Services {
    use IMAOCustom\Services\MonthlyTuition;
    use PHPUnit\Framework\TestCase;

    final class MonthlyClassEligibilityTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['class_eligibility_posts'] = [
                100 => (object) [ 'post_type' => 'imao_monthly_class', 'post_status' => 'publish' ],
                200 => (object) [ 'post_type' => 'club_application', 'post_status' => 'publish', 'post_author' => 2 ],
            ];
            $GLOBALS['class_eligibility_meta'] = [ 100 => [ '_class_coach_id' => 2, '_class_club_id' => 200,
                '_class_approved' => 'yes', '_class_shares' => [ 'referrer' => 20, 'coach' => 30, 'club' => 50 ] ] ];
            $GLOBALS['class_eligibility_users'] = [ 1 => [ 'coach_id' => 2, 'referrer_id' => 3 ],
                2 => [ 'identity_verified_professional' => 'approved' ] ];
            $GLOBALS['class_eligibility_accounts'] = [ 1 => (object) [ 'ID' => 1, 'roles' => [ 'subscriber' ] ],
                2 => (object) [ 'ID' => 2, 'roles' => [ 'subscriber', 'coach' ] ], 3 => (object) [ 'ID' => 3 ] ];
        }

        public function test_multi_role_coach_and_shared_club_owner_are_valid(): void {
            $snapshot = MonthlyTuition::checkout_snapshot( 100, 1 );
            $this->assertSame( [ 'referrer' => 3, 'coach' => 2, 'club' => 2 ], $snapshot['recipients'] );
            $GLOBALS['class_eligibility_meta'][100]['_class_shares']['coach'] = 10;
            $this->assertSame( 30, $snapshot['shares']['coach'] );
        }

        /** @dataProvider invalid_contexts */
        public function test_invalid_context_cannot_purchase( string $case ): void {
            $user = 1;
            switch ( $case ) {
                case 'guest': $user = 0; break;
                case 'different coach': $GLOBALS['class_eligibility_users'][1]['coach_id'] = 4; break;
                case 'unapproved class': $GLOBALS['class_eligibility_meta'][100]['_class_approved'] = 'no'; break;
                case 'pending class': $GLOBALS['class_eligibility_posts'][100]->post_status = 'pending'; break;
                case 'unapproved coach': $GLOBALS['class_eligibility_users'][2]['identity_verified_professional'] = 'pending'; break;
                case 'removed coach role': $GLOBALS['class_eligibility_accounts'][2]->roles = [ 'subscriber' ]; break;
                case 'wrong club owner': $GLOBALS['class_eligibility_posts'][200]->post_author = 4; break;
                case 'unapproved club': $GLOBALS['class_eligibility_posts'][200]->post_status = 'pending'; break;
                case 'missing referrer': unset( $GLOBALS['class_eligibility_accounts'][3] ); break;
            }
            $this->expectException( \RuntimeException::class );
            MonthlyTuition::checkout_snapshot( 100, $user );
        }

        public function invalid_contexts(): array {
            return array_map( static function ( $case ) { return [ $case ]; }, [ 'guest', 'different coach',
                'unapproved class', 'pending class', 'unapproved coach', 'removed coach role', 'wrong club owner',
                'unapproved club', 'missing referrer' ] );
        }
    }
}
