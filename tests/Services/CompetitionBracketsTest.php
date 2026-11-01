<?php

namespace {
    use IMAOCustom\Services\CompetitionBrackets;
    use PHPUnit\Framework\TestCase;

    class CompetitionBracketsTest extends TestCase {
        public function test_three_entrant_sections_choose_the_bye_side_that_avoids_a_same_province_fight(): void {
            $entries = [];
            for ( $i = 1; $i <= 10; ++$i ) $entries[] = [ 'entry_id' => (string) $i, 'name' => 'نام ' . $i, 'club' => 'club-' . $i,
                'province' => $i <= 5 ? 'THR' : 'GZN', 'seed_rank' => $i <= 4 ? $i : 0 ];
            foreach ( [ 'deployment-read-only', 'sample-draw', 'another-seed' ] as $seed ) {
                foreach ( [ 'province', 'club' ] as $criterion ) {
                    $slots = CompetitionBrackets::seed_participants( $entries, 16, $criterion, $seed );
                    $this->assertSame( 0, \IMAOCustom\Services\CompetitionBracketView::opening_collisions( $slots, 'province' ), $seed . ' ' . $criterion );
                }
            }
        }

        public function test_sample_provinces_avoid_opening_collisions_with_four_tehran_seeds(): void {
            $entries = [];
            for ( $i = 1; $i <= 10; ++$i ) {
                $entries[] = [ 'entry_id' => (string) $i, 'club' => 'club-' . $i,
                    'province' => $i <= 5 ? 'THR' : [ 'GZN', 'ABZ', 'ZJN', 'QHM', 'ESF' ][ $i - 6 ],
                    'seed_rank' => $i <= 4 ? $i : 0 ];
            }
            foreach ( [ 'province', 'club' ] as $criterion ) {
                for ( $seed = 0; $seed < 20; ++$seed ) {
                    $slots = CompetitionBrackets::seed_participants( $entries, 16, $criterion, 'sample-' . $seed );
                    $this->assertSame( 0, \IMAOCustom\Services\CompetitionBracketView::opening_collisions( $slots, 'province' ), $criterion . ' seed ' . $seed );
                    $this->assertSame( 0, CompetitionBrackets::empty_opening_matches( $slots ) );
                }
            }
        }

        public function test_province_aliases_are_one_protected_group(): void {
            $participants = [
                [ 'entry_id' => 'a', 'province' => 'THR' ],
                [ 'entry_id' => 'b', 'province' => 'IR-08' ],
                [ 'entry_id' => 'c', 'province' => 'GZN' ],
                [ 'entry_id' => 'd', 'province' => 'ZJN' ],
            ];
            $slots = CompetitionBrackets::seed_participants( $participants, 4, 'province', 'province-aliases' );
            $positions = [];
            foreach ( $slots as $slot => $entry ) $positions[ $entry['entry_id'] ] = $slot;
            $this->assertSame( 2, CompetitionBrackets::encounter_round( $positions['a'], $positions['b'] ) );
        }

        public function test_byes_are_balanced_in_every_half_and_quarter(): void {
            foreach ( [ 5, 6, 9, 10, 11, 13, 17, 21, 33 ] as $count ) {
                $entries = [];
                for ( $i = 1; $i <= $count; ++$i ) $entries[] = [ 'entry_id' => (string) $i, 'name' => 'Fighter ' . $i, 'club' => 'club-' . ( $i % 5 ), 'seed_rank' => $i <= 4 ? $i : 0 ];
                $size = CompetitionBrackets::bracket_size( $count );
                $slots = CompetitionBrackets::seed_participants( $entries, $size, 'club', 'balanced-byes' );
                for ( $section = (int) ( $size / 2 ); $section >= 2; $section = (int) ( $section / 2 ) ) {
                    $counts = array_map( static fn( array $chunk ): int => count( array_filter( $chunk ) ), array_chunk( $slots, $section ) );
                    $this->assertLessThanOrEqual( 1, max( $counts ) - min( $counts ), 'Uneven BYEs for ' . $count . ' entrants in sections of ' . $section );
                }
            }
        }

        public function test_opening_matches_have_an_entrant_for_every_supported_size(): void {
            foreach ( [ 2, 3, 5, 9, 17, 33, 65, 129, 256 ] as $count ) {
                foreach ( [ 'first', 'second', 'third' ] as $seed ) {
                    $participants = [];
                    for ( $i = 1; $i <= $count; $i++ ) {
                        $participants[] = [ 'entry_id' => (string) $i, 'name' => 'Fighter ' . $i, 'club' => 'club-' . ( $i % 3 ), 'seed_rank' => $i <= 4 ? $i : 0 ];
                    }
                    $slots = CompetitionBrackets::seed_participants( $participants, CompetitionBrackets::bracket_size( $count ), 'club', $seed );
                    $this->assertCount( $count, array_filter( $slots ) );
                    for ( $pair = 0; $pair < count( $slots ); $pair += 2 ) {
                        $this->assertTrue( $slots[ $pair ] !== null || $slots[ $pair + 1 ] !== null, 'Empty opening match for ' . $count );
                    }
                }
            }
        }

        public function test_pending_results_are_distinct_from_empty_matches(): void {
            $a = [ 'entry_id' => 'a', 'name' => 'A' ];
            $b = [ 'entry_id' => 'b', 'name' => 'B' ];
            $rounds = CompetitionBrackets::build_rounds( [ $a, $b, null, null ], [] );
            $this->assertTrue( $rounds[0][1]['empty'] );
            $this->assertTrue( $rounds[1][0]['a_pending'] );
            $this->assertFalse( $rounds[1][0]['empty'] );
            $this->assertNull( $rounds[1][0]['winner'] );
        }

        public function test_empty_saved_opening_matches_are_detected_without_changing_slots(): void {
            $slots = [ [ 'entry_id' => 'a' ], null, null, null, [ 'entry_id' => 'b' ], null, null, null ];
            $this->assertSame( 2, CompetitionBrackets::empty_opening_matches( $slots ) );
            $this->assertCount( 8, $slots );
        }

        public function test_medals_follow_the_final_and_semifinal_opponents(): void {
            $entries = [];
            foreach ( [ 'a', 'b', 'c', 'd' ] as $id ) $entries[] = [ 'entry_id' => $id, 'name' => $id ];
            $rounds = CompetitionBrackets::build_rounds( $entries, [ '0:0' => 'b', '0:1' => 'd', '1:0' => 'd' ] );
            $this->assertSame( [ 1 => 'd', 2 => 'b', 3 => 'c', 4 => 'a' ], array_map( static fn( $entry ) => $entry['entry_id'], CompetitionBrackets::placements( $rounds ) ) );
            $this->assertSame( [ 1 => null, 2 => null, 3 => null, 4 => null ], CompetitionBrackets::placements( CompetitionBrackets::build_rounds( $entries, [ '0:0' => 'b', '0:1' => 'd' ] ) ) );
            $rounds = CompetitionBrackets::build_rounds( [ $entries[0], null, $entries[2], $entries[3] ], [ '0:1' => 'd', '1:0' => 'a' ] );
            $medals = CompetitionBrackets::placements( $rounds );
            $this->assertNull( $medals[3] );
            $this->assertSame( 'c', $medals[4]['entry_id'] );
        }

        public function test_competition_levels_select_the_requested_identity(): void {
            foreach ( [ 'استانی' => 'club', 'بین المللی' => 'country', 'بین‌المللی' => 'country', 'کشوری' => 'province', 'جایزه بزرگ' => 'club', 'منطقه‌ای' => 'club', 'شهرستانی' => 'club' ] as $level => $criterion ) {
                $this->assertSame( $criterion, CompetitionBrackets::criterion_for_level( $level ) );
            }
            $this->assertSame( 'تهران', CompetitionBrackets::group_label( [ 'province' => 'THR' ], 'province' ) );
            $this->assertSame( 'خوزستان', CompetitionBrackets::group_label( [ 'province' => 'IR-13' ], 'province' ) );
            $this->assertSame( 'ایران', CompetitionBrackets::group_label( [ 'country' => 'IR' ], 'country' ) );
            $this->assertSame( 'ایالات متحده', CompetitionBrackets::group_label( [ 'country' => 'US' ], 'country' ) );
            $this->assertSame( 'رشت', CompetitionBrackets::group_label( [ 'city' => 'رشت' ], 'city' ) );
        }

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
            $this->assertSame( $first, CompetitionBrackets::seed_participants( array_reverse( $participants ), 4, 'province', 'audit-seed' ) );
        }

        public function test_top_four_ranked_fighters_are_protected_until_semifinals_and_final(): void {
            $participants = [];
            for ( $rank = 1; $rank <= 8; $rank++ ) {
                $participants[] = [
                    'entry_id' => 'fighter-' . $rank,
                    'name' => 'Fighter ' . $rank,
                    'club' => 'club-' . $rank,
                    'seed_rank' => $rank <= 4 ? $rank : 0,
                ];
            }
            $slots = CompetitionBrackets::seed_participants( $participants, 8, 'club', 'ranking-seed' );
            $positions = [];
            foreach ( $slots as $slot => $entry ) {
                if ( ! empty( $entry['seed_rank'] ) ) $positions[ $entry['seed_rank'] ] = $slot;
            }
            $this->assertSame( 3, CompetitionBrackets::encounter_round( $positions[1], $positions[2] ) );
            $this->assertSame( 2, CompetitionBrackets::encounter_round( $positions[1], $positions[3] ) );
            $this->assertSame( 2, CompetitionBrackets::encounter_round( $positions[2], $positions[4] ) );
            $this->assertSame( 3, CompetitionBrackets::encounter_round( $positions[3], $positions[4] ) );
        }
    }
}
