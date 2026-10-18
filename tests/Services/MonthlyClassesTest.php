<?php

use IMAOCustom\Services\MonthlyClasses;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'wp_unslash' ) ) {
    function wp_unslash( $value ) { return stripslashes( $value ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
}

class MonthlyClassesTest extends TestCase {
    public function test_directory_choices_require_approved_coaches_and_published_clubs(): void {
        $queries = MonthlyClasses::directory_option_queries();
        $this->assertSame( 'coach', $queries['coaches']['role'] );
        $this->assertSame( 'identity_verified_professional', $queries['coaches']['meta_key'] );
        $this->assertSame( 'approved', $queries['coaches']['meta_value'] );
        $this->assertSame( [ 'ID', 'display_name' ], $queries['coaches']['fields'] );
        $this->assertSame( 'display_name', $queries['coaches']['orderby'] );
        $this->assertSame( 'club_application', $queries['clubs']['post_type'] );
        $this->assertSame( 'publish', $queries['clubs']['post_status'] );
        $this->assertSame( -1, $queries['clubs']['posts_per_page'] );
    }

    public function test_optional_dropdowns_clear_shortcode_id_defaults(): void {
        $filters = MonthlyClasses::directory_filters( [ 'coach_id' => 12, 'club_id' => 34 ], [
            'imao_class_list_coach_id' => '0', 'imao_class_list_club_id' => '0',
        ] );
        $this->assertSame( 0, $filters['coach_id'] );
        $this->assertSame( 0, $filters['club_id'] );
        $this->assertCount( 2, MonthlyClasses::directory_query_args( $filters )['meta_query'] );
    }

    public function test_directory_defaults_do_not_restrict_selected_coach(): void {
        $filters = MonthlyClasses::directory_filters( [], [] );
        $args = MonthlyClasses::directory_query_args( $filters );
        $this->assertSame( 'publish', $args['post_status'] );
        $this->assertSame( 20, $args['posts_per_page'] );
        $this->assertSame( [ 'title' => 'ASC', 'ID' => 'ASC' ], $args['orderby'] );
        $this->assertSame( [ 'relation' => 'AND', [ 'key' => '_class_approved', 'value' => 'yes', 'compare' => '=' ] ], $args['meta_query'] );
    }

    public function test_directory_rejects_nested_inputs_and_preserves_defaults(): void {
        $filters = MonthlyClasses::directory_filters(
            [ 'province' => 'THR', 'coach_id' => 12, 'per_page' => 9, 'order' => 'DESC' ],
            [ 'imao_class_list_province' => [], 'imao_class_list_coach_id' => [ 88 ], 'coach_id' => 99 ]
        );
        $this->assertSame( 'THR', $filters['province'] );
        $this->assertSame( 12, $filters['coach_id'] );
        $this->assertSame( 9, $filters['per_page'] );
        $this->assertSame( 'DESC', $filters['order'] );
    }

    public function test_directory_bounds_and_sort_allowlist(): void {
        $filters = MonthlyClasses::directory_filters( [], [
            'imao_class_list_per_page' => '999', 'imao_class_list_page' => '-2',
            'imao_class_list_order' => 'DESC; DROP TABLE posts', 'imao_class_list_coach_id' => '-7',
        ] );
        $this->assertSame( 100, $filters['per_page'] );
        $this->assertSame( 1, $filters['page'] );
        $this->assertSame( 0, $filters['coach_id'] );
        $this->assertSame( 'ASC', $filters['order'] );
        $this->assertSame( 1, MonthlyClasses::directory_filters( [ 'per_page' => 0 ], [] )['per_page'] );
    }

    public function test_directory_geography_fails_closed_and_combines_filters(): void {
        $filters = MonthlyClasses::directory_filters( [ 'province' => 'THR', 'city' => 'تهران', 'coach_id' => 12, 'club_id' => 34, 'search' => 'کلاس', 'order' => 'desc' ], [] );
        $args = MonthlyClasses::directory_query_args( $filters );
        $this->assertSame( 'کلاس', $args['s'] );
        $this->assertSame( 'DESC', $args['orderby']['title'] );
        $this->assertSame( 12, $args['meta_query'][1]['value'] );
        $this->assertSame( 34, $args['meta_query'][2]['value'] );
        $this->assertSame( [ 0 ], $args['meta_query'][3]['value'] );
        $this->assertSame( [ 34, 35 ], MonthlyClasses::directory_query_args( $filters, [ 34, 35 ] )['meta_query'][3]['value'] );
    }

    public function test_all_account_endpoints_are_registered_as_wc_query_vars(): void {
        $vars = ( new MonthlyClasses() )->account_query_vars( [ 'orders' => 'orders' ] );
        foreach ( [ 'orders', 'monthly-classes', 'monthly-class-submit', 'monthly-class-list', 'monthly-class-payments' ] as $endpoint ) {
            $this->assertSame( $endpoint, $vars[ $endpoint ] );
        }
    }

    public function test_shares_accept_exact_decimal_total(): void {
        $this->assertSame(
            [ 'referrer' => 33.33, 'coach' => 33.33, 'club' => 33.34 ],
            MonthlyClasses::validate_shares( [ 'referrer' => '33.33', 'coach' => '33.33', 'club' => '33.34' ] )
        );
    }

    /** @dataProvider invalid_shares */
    public function test_invalid_shares_are_rejected( $shares ): void {
        $this->assertNull( MonthlyClasses::validate_shares( $shares ) );
    }

    public function invalid_shares(): array {
        return [
            'missing configuration' => [ null ],
            'zero recipient' => [ [ 'referrer' => 0, 'coach' => 50, 'club' => 50 ] ],
            'negative recipient' => [ [ 'referrer' => -10, 'coach' => 60, 'club' => 50 ] ],
            'under total' => [ [ 'referrer' => 30, 'coach' => 30, 'club' => 30 ] ],
            'over total' => [ [ 'referrer' => 40, 'coach' => 40, 'club' => 40 ] ],
            'extra recipient' => [ [ 'referrer' => 30, 'coach' => 30, 'club' => 40, 'other' => 1 ] ],
            'missing recipient' => [ [ 'referrer' => 50, 'coach' => 50 ] ],
            'nested input' => [ [ 'referrer' => [], 'coach' => 50, 'club' => 50 ] ],
            'excess precision' => [ [ 'referrer' => '33.333', 'coach' => '33.333', 'club' => '33.334' ] ],
            'scientific notation' => [ [ 'referrer' => '1e1', 'coach' => 40, 'club' => 50 ] ],
        ];
    }
}
