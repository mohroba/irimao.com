<?php

namespace IMAOCustom\Services {
    function current_user_can( $capability ) { return $GLOBALS['listing_admin']; }
    function get_userdata( $id ) { return (object) [ 'roles' => $GLOBALS['listing_roles'] ]; }
    function get_current_user_id() { return $GLOBALS['listing_user']; }
    function is_user_logged_in() { return get_current_user_id() > 0; }
    function get_user_meta( $id, $key, $single = true ) {
        return $key === 'coach_id' ? 2 : $GLOBALS['listing_birth'];
    }
    function get_terms( $args ) { return array_values( $GLOBALS['listing_terms'] ); }
    function get_term( $id, $taxonomy ) { return $GLOBALS['listing_terms'][$id] ?? false; }
    function get_term_meta( $id, $key, $single = true ) { return $GLOBALS['listing_bounds'][$id][$key] ?? ''; }
    function is_wp_error( $value ) { return false; }
    function absint( $value ) { return abs( (int) $value ); }
    function wp_unslash( $value ) { return $value; }
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
}

namespace {
    /** Stop at the actual query boundary, before renderer and billing dependencies. */
    class MonthlyListingQueryCaptured extends \RuntimeException {}
    class WP_Query {
        public function __construct( array $args ) {
            $GLOBALS['listing_query'] = $args;
            throw new MonthlyListingQueryCaptured();
        }
    }
}

namespace IMAOCustom\Tests\Services {
    use IMAOCustom\Helpers\Date;
    use IMAOCustom\Services\MonthlyClasses;
    use IMAOCustom\Services\MonthlyClassRestrictions;
    use PHPUnit\Framework\TestCase;

    final class MonthlyClassStudentListingTest extends TestCase {
        protected function setUp(): void {
            $GLOBALS['listing_admin'] = false;
            $GLOBALS['listing_roles'] = [ 'subscriber' ];
            $GLOBALS['listing_user'] = 1;
            $GLOBALS['listing_birth'] = '۱۳۹۰/۰۲/۰۳';
            $age = Date::age( $GLOBALS['listing_birth'] );
            $GLOBALS['listing_terms'] = [];
            $GLOBALS['listing_bounds'] = [];
            // Both inclusive boundaries match; the other category and weight child do not.
            foreach ( [ 10 => [ $age, $age + 1, 0 ], 20 => [ $age - 1, $age, 0 ],
                30 => [ $age + 1, $age + 5, 0 ], 40 => [ $age, $age, 10 ],
                50 => [ '', '', 0 ] ] as $id => $data ) {
                $GLOBALS['listing_terms'][$id] = (object) [ 'term_id' => $id, 'parent' => $data[2] ];
                $GLOBALS['listing_bounds'][$id] = [ 'age_start' => (string) $data[0], 'age_end' => (string) $data[1] ];
            }
            $_GET = [];
        }

        private function capture( string $method, array $attributes = [] ): array {
            try { ( new MonthlyClasses() )->$method( $attributes ); $this->fail( 'Query not reached.' ); }
            catch ( \MonthlyListingQueryCaptured $captured ) { return $GLOBALS['listing_query']; }
        }

        public function test_both_student_lists_apply_age_filter_before_pagination(): void {
            $_GET['monthly_class_page'] = '3';
            $_GET['imao_class_list_page'] = '3';
            foreach ( [ 'classes_shortcode', 'directory_shortcode' ] as $method ) {
                $args = $this->capture( $method );
                $this->assertSame( 3, $args['paged'] );
                $this->assertSame( 20, $args['posts_per_page'] );
                $this->assertSame( 'AND', $args['meta_query']['relation'] );
                $visibility = $args['meta_query'][1];
                $this->assertSame( 'OR', $visibility['relation'] );
                $this->assertSame( [ 'key' => '_class_age_category', 'compare' => 'NOT EXISTS' ], $visibility[0] );
                $this->assertSame( [ 10, 20 ], $visibility[1]['value'] );
                $this->assertSame( 'IN', $visibility[1]['compare'] );
                $this->assertSame( 'NUMERIC', $visibility[1]['type'] );
                $this->assertArrayNotHasKey( 'no_found_rows', $args );
                $this->assertNotEmpty( $args['meta_query'][0] );
            }
        }

        public function test_invalid_birth_or_no_matching_age_keeps_only_legacy_classes(): void {
            foreach ( [ '', 'invalid', '۱۵۰۰/۰۱/۰۱', '۱۳۰۰/۰۱/۰۱', '1390/13/01', '1390/12/31', [] ] as $birth ) {
                $GLOBALS['listing_birth'] = $birth;
                foreach ( [ 'classes_shortcode', 'directory_shortcode' ] as $method ) {
                    $args = $this->capture( $method );
                    $this->assertSame( [ 'relation' => 'OR', [ 'key' => '_class_age_category', 'compare' => 'NOT EXISTS' ] ], $args['meta_query'][1] );
                }
            }
        }

        public function test_admin_coach_and_guest_directory_queries_are_preserved(): void {
            $base = MonthlyClasses::directory_query_args( MonthlyClasses::directory_filters( [], [] ) );
            foreach ( [ 'admin', 'coach', 'guest' ] as $context ) {
                $GLOBALS['listing_admin'] = $context === 'admin';
                $GLOBALS['listing_roles'] = $context === 'coach' ? [ 'subscriber', 'coach' ] : [ 'subscriber' ];
                $GLOBALS['listing_user'] = $context === 'guest' ? 0 : 1;
                $this->assertSame( $base, $this->capture( 'directory_shortcode' ) );
            }
        }

        public function test_directory_coach_club_and_geographic_filters_remain_required(): void {
            $filters = MonthlyClasses::directory_filters( [ 'coach_id' => 2, 'club_id' => 5, 'province' => 'THR', 'per_page' => 7, 'page' => 2 ], [] );
            $base = MonthlyClasses::directory_query_args( $filters, [ 5, 6 ] );
            $args = MonthlyClasses::student_query_args( $base, 1 );
            $this->assertSame( $base['meta_query'], $args['meta_query'][0] );
            $this->assertSame( 7, $args['posts_per_page'] );
            $this->assertSame( 2, $args['paged'] );
            $GLOBALS['listing_terms'] = [];
            $this->assertCount( 2, MonthlyClassRestrictions::visibility_meta_query( 1 ) );
        }
    }
}
