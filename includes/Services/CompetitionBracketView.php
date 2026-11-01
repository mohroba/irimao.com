<?php

namespace IMAOCustom\Services;

/** Render the saved draw without changing slots, results or participant records. */
final class CompetitionBracketView {
    public static function render( array $divisions, string $criterion ): string {
        ob_start();
        echo '<style>' . file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/competition-brackets.css' ) . '</style>';
        foreach ( $divisions as $division ) {
            $slots = array_values( $division['slots'] ?? [] );
            if ( count( $slots ) < 2 ) continue;
            $rounds = CompetitionBrackets::build_rounds( $slots, $division['winners'] ?? [] );
            $summary = self::summary( $slots );
            $opening_province_conflicts = self::opening_collisions( $slots, 'province' );
            $numbers = self::match_numbers( $slots );
            $entry_slots = [];
            foreach ( $slots as $index => $entry ) {
                if ( $entry ) $entry_slots[ (string) $entry['entry_id'] ] = $index + 1;
            }
            $champion = $rounds[ count( $rounds ) - 1 ][0]['winner'];
            $protected_count = count( array_filter( $slots, static fn( $entry ): bool => $entry && ! empty( $entry['seed_rank'] ) ) );
            $criterion_label = [ 'club' => 'باشگاه', 'city' => 'شهرستان', 'province' => 'استان', 'country' => 'کشور' ][ $criterion ] ?? $criterion;
            require dirname( __DIR__ ) . '/Views/competition-bracket.php';
        }
        echo '<script>' . file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/competition-brackets.js' ) . '</script>';
        return (string) ob_get_clean();
    }

    public static function summary( array $slots ): array {
        $provinces = [];
        $byes = [];
        $count = 0;
        $opening = 0;
        foreach ( $slots as $index => $entry ) {
            if ( ! $entry ) {
                $opponent = $slots[ $index ^ 1 ] ?? null;
                $byes[] = [ 'slot' => $index + 1, 'opponent' => $opponent['name'] ?? 'جایگاه خالی' ];
                continue;
            }
            ++$count;
            $province = self::province( $entry );
            $provinces[ $province ] = ( $provinces[ $province ] ?? 0 ) + 1;
            if ( $index % 2 === 0 && ! empty( $slots[ $index + 1 ] ) ) ++$opening;
        }
        arsort( $provinces );
        return [ 'entrants' => $count, 'capacity' => count( $slots ), 'byes' => $byes, 'provinces' => $provinces,
            'contests' => max( 0, $count - 1 ), 'opening_contests' => $opening ];
    }

    /** Only nodes with entrants in both feeder subtrees represent an actual bout. */
    public static function match_numbers( array $slots ): array {
        $counts = array_map( static fn( $entry ): int => $entry ? 1 : 0, $slots );
        $numbers = [];
        $number = 0;
        while ( count( $counts ) >= 2 ) {
            $next = [];
            $round = [];
            for ( $i = 0; $i < count( $counts ); $i += 2 ) {
                $right = $counts[ $i + 1 ] ?? 0;
                $round[] = $counts[ $i ] > 0 && $right > 0 ? ++$number : null;
                $next[] = $counts[ $i ] + $right;
            }
            $numbers[] = $round;
            $counts = $next;
        }
        return $numbers;
    }

    public static function province( array $entry ): string {
        return CompetitionBrackets::group_label( $entry, 'province' );
    }

    public static function opening_collisions( array $slots, string $criterion ): int {
        $collisions = 0;
        for ( $i = 0; $i + 1 < count( $slots ); $i += 2 ) {
            $a = $slots[ $i ];
            $b = $slots[ $i + 1 ];
            if ( ! $a || ! $b || empty( $a[ $criterion ] ) || empty( $b[ $criterion ] ) ) continue;
            $left = $criterion === 'province' ? self::province( $a ) : (string) $a[ $criterion ];
            $right = $criterion === 'province' ? self::province( $b ) : (string) $b[ $criterion ];
            if ( strpos( $left, 'ثبت نشده' ) !== false || strpos( $right, 'ثبت نشده' ) !== false ) continue;
            if ( $left === $right ) ++$collisions;
        }
        return $collisions;
    }

    public static function province_color( string $province ): string {
        $known = [ 'تهران' => '#0875b9', 'قزوین' => '#278343', 'البرز' => '#7041b5', 'زنجان' => '#b85109', 'قم' => '#007c7c', 'اصفهان' => '#b22b7f' ];
        if ( isset( $known[ $province ] ) ) return $known[ $province ];
        if ( $province === 'استان ثبت نشده' || $province === 'بدون استان' ) return '#64748b';
        return 'hsl(' . ( hexdec( substr( hash( 'sha256', $province ), 0, 6 ) ) % 360 ) . ',58%,28%)';
    }

    private static function round_label( int $size, int $round ): string {
        $remaining = (int) ( $size / ( 2 ** $round ) );
        return [ 2 => 'فینال', 4 => 'نیمه‌نهایی', 8 => 'یک‌چهارم نهایی' ][ $remaining ] ?? 'مرحلهٔ ' . $remaining . ' نفره';
    }

    private static function pending_label( int $round, int $match, string $side, array $numbers ): string {
        $source = $numbers[ $round - 1 ][ $match * 2 + ( $side === 'b' ? 1 : 0 ) ] ?? null;
        return $source ? 'برندهٔ مبارزهٔ ' . $source : 'در انتظار نتیجه';
    }
}
