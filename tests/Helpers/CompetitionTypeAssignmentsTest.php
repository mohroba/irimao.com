<?php

use IMAOCustom\Helpers\CompetitionTypeAssignments;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_option' ) ) {
    function get_option( $key, $default = [] ) {
        if ( ! isset( $GLOBALS['test_options'] ) ) {
            $GLOBALS['test_options'] = [];
        }
        return $GLOBALS['test_options'][ $key ] ?? $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( $key, $value ) {
        if ( ! isset( $GLOBALS['test_options'] ) ) {
            $GLOBALS['test_options'] = [];
        }
        $GLOBALS['test_options'][ $key ] = $value;
        return true;
    }
}

class CompetitionTypeAssignmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['test_options'] = [];
    }

    public function test_types_for_ages_returns_unique_ids(): void
    {
        $GLOBALS['test_options'][ 'imao_age_category_type_map' ] = [
            10 => [ 1, '2', 2, 'invalid' ],
            11 => [ '5', 6 ],
        ];

        $ids = CompetitionTypeAssignments::types_for_ages( [ 10, 11, 99 ] );

        sort( $ids );
        $this->assertSame( [ 1, 2, 5, 6 ], $ids );
    }

    public function test_save_map_filters_empty_entries(): void
    {
        CompetitionTypeAssignments::save_map( [
            10 => [ '1', '2', ''],
            'foo' => [ 3 ],
            12 => [],
        ] );

        $stored = CompetitionTypeAssignments::get_map();
        $this->assertSame( [ 10 => [ 1, 2 ] ], $stored );
    }
}
