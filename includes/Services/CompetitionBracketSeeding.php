<?php

namespace IMAOCustom\Services;

/** Balance entrants and protected groups recursively while preserving ranked slots. */
final class CompetitionBracketSeeding {
    public static function seed( array $participants, int $size, string $criterion, string $seed ): array {
        if ( $size < 2 || $size > 256 || ( $size & ( $size - 1 ) ) !== 0 || count( $participants ) > $size ) {
            throw new \InvalidArgumentException( 'Invalid tournament capacity.' );
        }
        $positions = $size === 4 ? [ 1 => 0, 2 => 2, 3 => 3, 4 => 1 ]
            : [ 1 => 0, 2 => (int) ( $size / 2 ), 3 => (int) ( $size / 4 ), 4 => (int) ( 3 * $size / 4 ) ];
        $fixed = [];
        $occupied = [];
        foreach ( $participants as &$entry ) {
            $entry['group_label'] = self::identity( $entry, $criterion );
            $rank = (int) ( $entry['seed_rank'] ?? 0 );
            if ( isset( $positions[ $rank ] ) && ! isset( $occupied[ $positions[ $rank ] ] ) ) {
                $fixed[ (string) $entry['entry_id'] ] = $positions[ $rank ];
                $occupied[ $positions[ $rank ] ] = true;
            }
        }
        unset( $entry );
        $slots = array_fill( 0, $size, null );
        self::place( $participants, 0, $size, $criterion, $seed, $fixed, $slots );
        return $slots;
    }

    private static function place( array $entries, int $start, int $size, string $criterion, string $seed, array $fixed, array &$slots ): void {
        if ( ! $entries ) return;
        if ( $size === 1 ) {
            $slots[ $start ] = $entries[0];
            return;
        }
        $half = (int) ( $size / 2 );
        $middle = $start + $half;
        $salt = $seed . '|' . $start . '|' . $size;
        usort( $entries, static fn( array $a, array $b ): int => strcmp( hash( 'sha256', $salt . '|' . $a['entry_id'] ), hash( 'sha256', $salt . '|' . $b['entry_id'] ) ) );
        $groups = [];
        $fixed_left = 0;
        $fixed_right = 0;
        foreach ( $entries as $entry ) {
            $key = self::identity( $entry, $criterion );
            if ( ! isset( $groups[ $key ] ) ) $groups[ $key ] = [ 'left' => [], 'right' => [], 'free' => [] ];
            $id = (string) $entry['entry_id'];
            if ( isset( $fixed[ $id ] ) ) {
                $side = $fixed[ $id ] < $middle ? 'left' : 'right';
                $groups[ $key ][ $side ][] = $entry;
                if ( $side === 'left' ) ++$fixed_left;
                else ++$fixed_right;
            } else {
                $groups[ $key ]['free'][] = $entry;
            }
        }
        $count = count( $entries );
        $minimum = max( $fixed_left, $count - $half );
        $maximum = min( $half, $count - $fixed_right );
        $quotas = array_unique( [ max( $minimum, min( $maximum, (int) floor( $count / 2 ) ) ), max( $minimum, min( $maximum, (int) ceil( $count / 2 ) ) ) ] );
        $best_score = null;
        $best_ticket = '';
        $left = $right = [];
        foreach ( $quotas as $quota ) {
            $allocation = self::allocate_groups( array_values( $groups ), $quota, $salt );
            $candidate_left = $candidate_right = [];
            foreach ( array_values( $groups ) as $index => $group ) {
                $free_left = $allocation['counts'][ $index ] - count( $group['left'] );
                $candidate_left = array_merge( $candidate_left, $group['left'], array_slice( $group['free'], 0, $free_left ) );
                $candidate_right = array_merge( $candidate_right, $group['right'], array_slice( $group['free'], $free_left ) );
            }
            if ( $criterion !== 'province' ) self::improve_geography( $candidate_left, $candidate_right, $criterion, $fixed );
            $score = [ $allocation['cost'], self::geography_cost( $candidate_left, $candidate_right ) ];
            $ticket = hash( 'sha256', $salt . '|quota|' . $quota );
            if ( $best_score === null || $score < $best_score || ( $score === $best_score && strcmp( $ticket, $best_ticket ) < 0 ) ) {
                $best_score = $score;
                $best_ticket = $ticket;
                $left = $candidate_left;
                $right = $candidate_right;
            }
        }
        self::place( $left, $start, $half, $criterion, $seed, $fixed, $slots );
        self::place( $right, $middle, $half, $criterion, $seed, $fixed, $slots );
    }

    /** Dynamic programming chooses the most balanced feasible protected-group split. */
    private static function allocate_groups( array $groups, int $quota, string $salt ): array {
        $costs = [ 0 => 0 ];
        $history = [];
        foreach ( $groups as $index => $group ) {
            $next = [];
            $tickets = [];
            $total = count( $group['left'] ) + count( $group['right'] ) + count( $group['free'] );
            $minimum = count( $group['left'] );
            $maximum = $total - count( $group['right'] );
            foreach ( $costs as $used => $cost ) {
                for ( $left = $minimum; $left <= min( $maximum, $quota - $used ); ++$left ) {
                    $target = $used + $left;
                    $score = $cost + $left * $left + ( $total - $left ) * ( $total - $left );
                    $ticket = hash( 'sha256', $salt . '|' . $index . '|' . $used . '|' . $left );
                    if ( ! isset( $next[ $target ] ) || $score < $next[ $target ] || ( $score === $next[ $target ] && strcmp( $ticket, $tickets[ $target ] ) < 0 ) ) {
                        $next[ $target ] = $score;
                        $tickets[ $target ] = $ticket;
                        $history[ $index ][ $target ] = [ $used, $left ];
                    }
                }
            }
            $costs = $next;
        }
        if ( ! isset( $costs[ $quota ] ) ) throw new \LogicException( 'Ranked slots cannot satisfy this bracket split.' );
        $best_cost = $costs[ $quota ];
        $result = [];
        for ( $index = count( $groups ) - 1; $index >= 0; --$index ) {
            [ $quota, $result[ $index ] ] = $history[ $index ][ $quota ];
        }
        return [ 'counts' => $result, 'cost' => $best_cost ];
    }

    private static function geography_cost( array $left, array $right ): int {
        $score = 0;
        foreach ( [ $left, $right ] as $entries ) {
            $counts = [];
            foreach ( $entries as $entry ) {
                $province = self::identity( $entry, 'province' );
                $counts[ $province ] = ( $counts[ $province ] ?? 0 ) + 1;
            }
            foreach ( $counts as $count ) $score += $count * $count;
        }
        return $score;
    }

    /** Improve province separation only among splits of equal primary-group quality. */
    private static function improve_geography( array &$left, array &$right, string $criterion, array $fixed ): void {
        $counts = [ 'primary' => [ [], [] ], 'province' => [ [], [] ] ];
        foreach ( [ $left, $right ] as $side => $entries ) {
            foreach ( $entries as $entry ) {
                foreach ( [ 'primary' => $criterion, 'province' => 'province' ] as $type => $field ) {
                    $key = self::identity( $entry, $field );
                    $counts[ $type ][ $side ][ $key ] = ( $counts[ $type ][ $side ][ $key ] ?? 0 ) + 1;
                }
            }
        }
        do {
            $best = null;
            $best_delta = 0;
            foreach ( $left as $i => $a ) {
                if ( isset( $fixed[ (string) $a['entry_id'] ] ) ) continue;
                foreach ( $right as $j => $b ) {
                    if ( isset( $fixed[ (string) $b['entry_id'] ] ) ) continue;
                    if ( self::swap_delta( self::identity( $a, $criterion ), self::identity( $b, $criterion ), $counts['primary'] ) !== 0 ) continue;
                    $delta = self::swap_delta( self::identity( $a, 'province' ), self::identity( $b, 'province' ), $counts['province'] );
                    if ( $delta < $best_delta ) { $best_delta = $delta; $best = [ $i, $j ]; }
                }
            }
            if ( $best !== null ) {
                [ $i, $j ] = $best;
                foreach ( [ 'primary' => $criterion, 'province' => 'province' ] as $type => $field ) {
                    $a = self::identity( $left[ $i ], $field );
                    $b = self::identity( $right[ $j ], $field );
                    if ( $a === $b ) continue;
                    --$counts[ $type ][0][ $a ];
                    $counts[ $type ][0][ $b ] = ( $counts[ $type ][0][ $b ] ?? 0 ) + 1;
                    --$counts[ $type ][1][ $b ];
                    $counts[ $type ][1][ $a ] = ( $counts[ $type ][1][ $a ] ?? 0 ) + 1;
                }
                [ $left[ $i ], $right[ $j ] ] = [ $right[ $j ], $left[ $i ] ];
            }
        } while ( $best !== null );
    }

    private static function swap_delta( string $a, string $b, array $counts ): int {
        if ( $a === $b ) return 0;
        return 2 * ( ( $counts[0][ $b ] ?? 0 ) - ( $counts[0][ $a ] ?? 0 ) + ( $counts[1][ $a ] ?? 0 ) - ( $counts[1][ $b ] ?? 0 ) ) + 4;
    }

    private static function identity( array $entry, string $criterion ): string {
        $raw = trim( (string) ( $entry[ $criterion ] ?? '' ) );
        if ( $raw !== '' && in_array( $criterion, [ 'province', 'country' ], true ) ) {
            $label = CompetitionBrackets::group_label( $entry, $criterion );
            if ( strpos( $label, 'ثبت نشده' ) === false ) return $label;
        }
        return $raw;
    }
}
