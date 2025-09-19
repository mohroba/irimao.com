<?php

use IMAOCustom\Helpers\CompetitionTypeAssignments;
use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = true ) {
        return $GLOBALS['test_post_meta'][ $post_id ][ $key ] ?? [];
    }
}

if ( ! function_exists( 'update_post_meta' ) ) {
    function update_post_meta( $post_id, $key, $value ) {
        if ( ! isset( $GLOBALS['test_post_meta'][ $post_id ] ) ) {
            $GLOBALS['test_post_meta'][ $post_id ] = [];
        }
        $GLOBALS['test_post_meta'][ $post_id ][ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_post_meta' ) ) {
    function delete_post_meta( $post_id, $key ) {
        unset( $GLOBALS['test_post_meta'][ $post_id ][ $key ] );
        return true;
    }
}

class CompetitionTypeAssignmentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['test_post_meta'] = [];
    }

    public function test_types_for_ages_returns_unique_ids(): void
    {
        $GLOBALS['test_post_meta'][ 99 ][ '_competition_type_assignments' ] = [
            10 => [ 1, '2', 2, 'invalid' ],
            11 => [ '5', 6 ],
        ];

        $ids = CompetitionTypeAssignments::types_for_ages( 99, [ 10, 11, 99 ] );

        sort( $ids );
        $this->assertSame( [ 1, 2, 5, 6 ], $ids );
    }

    public function test_save_map_filters_empty_entries(): void
    {
        CompetitionTypeAssignments::save_map( 77, [
            10 => [ '1', '2', ''],
            'foo' => [ 3 ],
            12 => [],
        ] );

        $stored = CompetitionTypeAssignments::get_map( 77 );
        $this->assertSame( [ 10 => [ 1, 2 ] ], $stored );
    }
}
