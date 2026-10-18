<?php

namespace IMAOCustom\Services {
    function wp_timezone(): \DateTimeZone {
        return new \DateTimeZone( 'Asia/Tehran' );
    }
}

namespace IMAOCustom\Tests\Services {
    use IMAOCustom\Services\MonthlyTuition;
    use Morilog\Jalali\Jalalian;
    use PHPUnit\Framework\TestCase;
    use RuntimeException;

    /** Run this file alone: existing service tests declare incompatible namespace stubs. */
    final class MonthlyTuitionTest extends TestCase {
        /** @dataProvider due_dates */
        public function test_next_due_date_respects_jalali_boundaries( array $paid, string $expected ): void {
            $date = new Jalalian( $paid[0], $paid[1], $paid[2], 23, 59, 59, new \DateTimeZone( 'Asia/Tehran' ) );
            $actual = MonthlyTuition::next_due_date( $date->toCarbon()->format( 'Y-m-d H:i:s' ) );

            $this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $actual );
            $this->assertSame( $expected, Jalalian::fromDateTime( new \DateTimeImmutable( $actual, new \DateTimeZone( 'Asia/Tehran' ) ) )->format( 'Y-m-d' ) );
        }

        public static function due_dates(): array {
            return [
                'first day' => [ [ 1402, 1, 1 ], '1402-02-01' ],
                'ordinary day' => [ [ 1402, 4, 15 ], '1402-05-15' ],
                '31 to 31' => [ [ 1402, 1, 31 ], '1402-02-31' ],
                '31 clamps to 30' => [ [ 1402, 6, 31 ], '1402-07-30' ],
                '30 to 30' => [ [ 1402, 7, 30 ], '1402-08-30' ],
                'common Esfand clamp' => [ [ 1402, 11, 30 ], '1402-12-29' ],
                'leap Esfand preserves 30' => [ [ 1399, 11, 30 ], '1399-12-30' ],
                'common year rollover' => [ [ 1402, 12, 29 ], '1403-01-29' ],
                'leap year rollover' => [ [ 1399, 12, 30 ], '1400-01-30' ],
            ];
        }

        public function test_due_date_is_independent_of_server_default_timezone(): void {
            $original = date_default_timezone_get();
            try {
                date_default_timezone_set( 'America/Los_Angeles' );
                $this->assertSame( '2024-04-20', MonthlyTuition::next_due_date( '2024-03-20 00:00:00' ) );
            } finally {
                date_default_timezone_set( $original );
            }
        }

        public function test_malformed_payment_date_is_rejected(): void {
            $this->expectException( \Exception::class );
            MonthlyTuition::next_due_date( 'not-a-date' );
        }

        /** @dataProvider split_examples */
        public function test_split_rounding_and_role_order( float $amount, array $shares, array $expected_cents ): void {
            $actual = MonthlyTuition::split_amount( $amount, $shares );
            $this->assertSame( [ 'referrer', 'coach', 'club' ], array_keys( $actual ) );
            $this->assertSame( $expected_cents, array_map( static function ( $value ): int {
                return (int) round( $value * 100 );
            }, array_values( $actual ) ) );
        }

        public static function split_examples(): array {
            $thirds = [ 'referrer' => '33.33', 'coach' => '33.33', 'club' => '33.34' ];
            return [
                'zero' => [ 0.0, $thirds, [ 0, 0, 0 ] ],
                'one cent remainder' => [ 0.01, $thirds, [ 0, 0, 1 ] ],
                'two cent remainder' => [ 0.02, $thirds, [ 0, 0, 2 ] ],
                'one unit' => [ 1.0, $thirds, [ 33, 33, 34 ] ],
                'round amount half up' => [ 1.005, $thirds, [ 33, 33, 35 ] ],
                'exact split' => [ 123.45, [ 'referrer' => 20, 'coach' => 30, 'club' => 50 ], [ 2469, 3703, 6173 ] ],
            ];
        }

        public function test_splits_conserve_cents_over_many_amounts_and_shares(): void {
            foreach ( [ [ 33.33, 33.33, 33.34 ], [ 0.01, 99.98, 0.01 ], [ 25, 25, 50 ] ] as $values ) {
                $shares = array_combine( [ 'referrer', 'coach', 'club' ], $values );
                foreach ( [ 0, 1, 2, 3, 99, 100, 101, 999, 12345, 99999999 ] as $cents ) {
                    $parts = MonthlyTuition::split_amount( $cents / 100, $shares );
                    $total = 0;
                    foreach ( $parts as $part ) {
                        $this->assertGreaterThanOrEqual( 0, $part );
                        $minor = (int) round( $part * 100 );
                        $this->assertEqualsWithDelta( $minor, $part * 100, 0.000001 );
                        $total += $minor;
                    }
                    $this->assertSame( $cents, $total );
                }
            }
        }

        /** @dataProvider invalid_splits */
        public function test_invalid_split_is_rejected( float $amount, array $shares ): void {
            $this->expectException( RuntimeException::class );
            MonthlyTuition::split_amount( $amount, $shares );
        }

        public static function invalid_splits(): array {
            $valid = [ 'referrer' => 20, 'coach' => 30, 'club' => 50 ];
            $cases = [];
            foreach ( [ -0.01, INF, -INF, NAN ] as $index => $amount ) {
                $cases['invalid amount ' . $index] = [ $amount, $valid ];
            }
            foreach ( [ 0, -1, 101, null, '', 'bad', [], true, INF, -INF, NAN ] as $index => $value ) {
                foreach ( array_keys( $valid ) as $role ) {
                    $cases[$role . ' invalid ' . $index] = [ 100.0, array_replace( $valid, [ $role => $value ] ) ];
                }
            }
            foreach ( array_keys( $valid ) as $role ) {
                $missing = $valid;
                unset( $missing[$role] );
                $cases['missing ' . $role] = [ 100.0, $missing ];
            }
            $cases['under total'] = [ 100.0, [ 'referrer' => 20, 'coach' => 30, 'club' => 49.99 ] ];
            $cases['over total'] = [ 100.0, [ 'referrer' => 20, 'coach' => 30, 'club' => 50.01 ] ];
            return $cases;
        }
    }
}
