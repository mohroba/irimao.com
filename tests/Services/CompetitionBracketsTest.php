<?php

namespace {
    use IMAOCustom\Services\CompetitionBrackets;
    use PHPUnit\Framework\TestCase;

    class CompetitionBracketsTest extends TestCase {
        public function test_bracket_expands_to_smallest_supported_power_of_two(): void {
            $this->assertSame( 2, CompetitionBrackets::bracket_size( 2 ) );
            $this->assertSame( 8, CompetitionBrackets::bracket_size( 5 ) );
            $this->assertSame( 16, CompetitionBrackets::bracket_size( 16 ) );
            $this->assertSame( 256, CompetitionBrackets::bracket_size( 129 ) );
        }

        public function test_three_members_of_same_group_are_maximally_separated_in_eight_slots(): void {
            $participants = [];
            foreach ( [ 'A', 'B', 'C' ] as $id ) {
                $participants[] = [ 'entry_id' => $id, 'name' => $id, 'club' => 'same-club' ];
            }
            foreach ( [ 'D', 'E', 'F' ] as $id ) {
                $participants[] = [ 'entry_id' => $id, 'name' => $id, 'club' => 'club-' . $id ];
            }
            $slots = CompetitionBrackets::seed_participants( $participants, 8, 'club', 'fixed-seed' );
            $same_slots = [];
            foreach ( $slots as $slot => $entry ) {
                if ( $entry && $entry['club'] === 'same-club' ) $same_slots[] = $slot;
            }
            $rounds = [];
            for ( $i = 0; $i < count( $same_slots ); $i++ ) {
                for ( $j = $i + 1; $j < count( $same_slots ); $j++ ) {
                    $rounds[] = CompetitionBrackets::encounter_round( $same_slots[$i], $same_slots[$j] );
                }
            }
            sort( $rounds );
            $this->assertSame( [ 2, 3, 3 ], $rounds );
        }

        public function test_byes_advance_and_invalid_winners_are_rejected(): void {
            $a = [ 'entry_id' => 'a', 'name' => 'A' ];
            $b = [ 'entry_id' => 'b', 'name' => 'B' ];
            $accepted = [];
            $rounds = CompetitionBrackets::build_rounds( [ $a, null, $b, null ], [ '1:0' => 'not-eligible' ], $accepted );
            $this->assertSame( 'a', $rounds[0][0]['winner']['entry_id'] );
            $this->assertSame( 'b', $rounds[0][1]['winner']['entry_id'] );
            $this->assertNull( $rounds[1][0]['winner'] );
            $this->assertSame( [], $accepted );
        }

        public function test_generation_is_reproducible_for_same_seed(): void {
            $participants = [
                [ 'entry_id' => 'a', 'name' => 'A', 'province' => 'p1' ],
                [ 'entry_id' => 'b', 'name' => 'B', 'province' => 'p1' ],
                [ 'entry_id' => 'c', 'name' => 'C', 'province' => 'p2' ],
            ];
            $first = CompetitionBrackets::seed_participants( $participants, 4, 'province', 'audit-seed' );
            $second = CompetitionBrackets::seed_participants( $participants, 4, 'province', 'audit-seed' );
            $this->assertSame( $first, $second );
        }
    }
}
