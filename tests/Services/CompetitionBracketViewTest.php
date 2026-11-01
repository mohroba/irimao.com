<?php

use IMAOCustom\Services\CompetitionBracketView;
use IMAOCustom\Services\CompetitionBrackets;
use PHPUnit\Framework\TestCase;

final class CompetitionBracketViewTest extends TestCase {
    public function test_render_reads_saved_results_and_numbers_only_real_matches(): void {
        $a = [ 'entry_id' => 'a', 'name' => 'الف', 'province' => 'THR' ];
        $b = [ 'entry_id' => 'b', 'name' => 'ب', 'province' => 'GZN' ];
        $division = [ 'label' => 'نمونه', 'slots' => [ $a, null, $b, null ], 'winners' => [ '1:0' => 'b' ] ];
        $html = CompetitionBracketView::render( [ $division ], 'province' );
        $this->assertMatchesRegularExpression( '/class="imao-bracket-champion"[^>]*>.*?<strong>ب<\/strong>/s', $html );
        $this->assertStringContainsString( 'data-round="1" data-match="0"', $html );
        $this->assertStringContainsString( 'مبارزهٔ 1', $html );
        $this->assertStringNotContainsString( 'visibility:hidden', $html );
        $this->assertStringContainsString( 'خانهٔ 2', $html );
        $this->assertStringContainsString( 'خانهٔ 4', $html );
        $this->assertSame( [ '1:0' => 'b' ], $division['winners'] );
    }

    public function test_ten_athletes_have_six_byes_and_nine_real_bouts(): void {
        $entries = [];
        for ( $i = 0; $i < 10; ++$i ) {
            $entries[] = [ 'entry_id' => 'fighter-' . $i, 'name' => 'نام ' . $i, 'province' => $i < 5 ? 'THR' : 'GZN', 'club' => 'club-' . $i, 'seed_rank' => $i < 4 ? $i + 1 : 0 ];
        }
        $slots = CompetitionBrackets::seed_participants( $entries, 16, 'province', 'sample-draw' );
        $summary = CompetitionBracketView::summary( $slots );
        $this->assertSame( 10, $summary['entrants'] );
        $this->assertCount( 6, $summary['byes'] );
        $this->assertSame( 9, $summary['contests'] );
        $this->assertSame( 2, $summary['opening_contests'] );
        $this->assertSame( 0, CompetitionBracketView::opening_collisions( $slots, 'province' ) );
        $this->assertEquals( [ 'تهران' => 5, 'قزوین' => 5 ], $summary['provinces'] );
        $numbers = [];
        foreach ( CompetitionBracketView::match_numbers( $slots ) as $round ) {
            foreach ( $round as $number ) if ( $number !== null ) $numbers[] = $number;
        }
        $this->assertSame( range( 1, 9 ), $numbers );
        foreach ( $summary['byes'] as $bye ) {
            $this->assertNull( $slots[ $bye['slot'] - 1 ] );
            $this->assertSame( $slots[ ( $bye['slot'] - 1 ) ^ 1 ]['name'], $bye['opponent'] );
        }
    }

    public function test_empty_subtrees_do_not_create_real_bout_numbers(): void {
        $a = [ 'entry_id' => 'a', 'name' => 'الف' ];
        $b = [ 'entry_id' => 'b', 'name' => 'ب' ];
        $this->assertSame( [ [ null, null, null, null ], [ null, null ], [ 1 ] ], CompetitionBracketView::match_numbers( [ $a, null, null, null, $b, null, null, null ] ) );
    }

    public function test_notes_report_actual_collisions_including_province_aliases(): void {
        $this->assertSame( 1, CompetitionBracketView::opening_collisions( [ [ 'province' => 'THR' ], [ 'province' => 'IR-08' ], [ 'province' => 'GZN' ], null ], 'province' ) );
        $this->assertSame( 0, CompetitionBracketView::opening_collisions( [ null, null, [ 'province' => '' ], [ 'province' => '' ] ], 'province' ) );
    }

    public function test_province_colors_are_stable_and_do_not_depend_on_other_entrants(): void {
        $this->assertSame( '#0875b9', CompetitionBracketView::province_color( 'تهران' ) );
        $this->assertNotSame( CompetitionBracketView::province_color( 'تهران' ), CompetitionBracketView::province_color( 'قزوین' ) );
        $this->assertSame( CompetitionBracketView::province_color( 'کرمان' ), CompetitionBracketView::province_color( 'کرمان' ) );
    }
}
