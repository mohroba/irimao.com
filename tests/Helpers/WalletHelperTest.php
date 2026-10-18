<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\Wallet;

class WalletHelperTest extends TestCase {
    private $previous_db;

    protected function setUp(): void {
        $this->previous_db = $GLOBALS['wpdb'];
        $GLOBALS['wpdb'] = new WalletDatabaseFake();
        $GLOBALS['wallet_cache_deletions'] = [];
    }

    protected function tearDown(): void {
        $GLOBALS['wpdb'] = $this->previous_db;
    }

    public function test_wallet_helpers(): void {
        if ( ! function_exists( 'get_user_meta' ) ) {
            $this->markTestSkipped( 'WordPress functions not available.' );
        }

        $user_id = 123;
        Wallet::set( $user_id, 100 );
        $this->assertSame( 100.0, Wallet::get( $user_id ) );
        Wallet::add( $user_id, 50 );
        $this->assertSame( 150.0, Wallet::get( $user_id ) );
        Wallet::deduct( $user_id, 70 );
        $this->assertSame( 80.0, Wallet::get( $user_id ) );
    }

    public function test_fresh_balance_after_user_lock_preserves_refund_debt(): void {
        $db = $GLOBALS['wpdb'];
        $GLOBALS['test_user_meta'][123]['_wallet_balance'] = 900;
        $db->rows = [];
        $db->on_lock = static function ( $db ): void {
            // Tuition committed while this operation waited for the user lock.
            $db->rows = [ (object) [ 'umeta_id' => 1, 'meta_value' => -100 ] ];
        };
        Wallet::add( 123, 25 );
        $this->assertSame( -75.0, $db->rows[0]->meta_value );
        $this->assertStringContainsString( 'SELECT ID FROM wp_users', $db->queries[3] );
        $this->assertStringContainsString( 'ORDER BY umeta_id FOR UPDATE', $db->queries[4] );
        $this->assertSame( 'COMMIT', end( $db->queries ) );
        $this->assertSame( [ [ 123, 'user_meta' ] ], $GLOBALS['wallet_cache_deletions'] );
    }

    public function test_missing_and_duplicate_rows_and_signed_operations(): void {
        $db = $GLOBALS['wpdb'];
        $db->rows = [];
        Wallet::add( 123, -20 );
        $this->assertSame( -20.0, $db->rows[0]->meta_value );
        $this->assertContains( 'insert', $db->queries );
        $db->rows[] = (object) [ 'umeta_id' => 2, 'meta_value' => 999 ];
        Wallet::set( 123, -50 );
        $this->assertSame( -50.0, $db->rows[1]->meta_value );
        Wallet::add( 123, 10 );
        $this->assertSame( -40.0, $db->rows[0]->meta_value );
        Wallet::deduct( 123, 5 );
        $this->assertSame( 0.0, $db->rows[0]->meta_value );
    }

    public function test_deduct_uses_committed_balance_and_set_is_absolute(): void {
        $db = $GLOBALS['wpdb'];
        $GLOBALS['test_user_meta'][123]['_wallet_balance'] = 100;
        $db->rows = [ (object) [ 'umeta_id' => 1, 'meta_value' => 100 ] ];
        $db->on_lock = static function ( $db ): void {
            $db->rows = [ (object) [ 'umeta_id' => 1, 'meta_value' => 175 ] ];
        };
        Wallet::deduct( 123, 40 );
        $this->assertSame( 135.0, $db->rows[0]->meta_value );
        Wallet::set( 123, 12 );
        $this->assertSame( 12.0, $db->rows[0]->meta_value );
        $db->on_lock = null;
        Wallet::deduct( 123, 100 );
        $this->assertSame( 0.0, $db->rows[0]->meta_value );
    }

    /** @dataProvider invalid_inputs */
    public function test_invalid_inputs_do_not_start_sql( string $method, int $user, float $amount ): void {
        try {
            Wallet::$method( $user, $amount );
            $this->fail( 'Expected invalid input rejection.' );
        } catch ( InvalidArgumentException $error ) {
            $this->assertSame( [], $GLOBALS['wpdb']->queries );
        }
    }

    public static function invalid_inputs(): array {
        $cases = [];
        foreach ( [ 'set', 'add', 'deduct' ] as $method ) {
            foreach ( [ [ 0, 1 ], [ -1, 1 ], [ 1, INF ], [ 1, -INF ], [ 1, NAN ] ] as $input ) {
                $cases[] = [ $method, $input[0], $input[1] ];
            }
        }
        $cases[] = [ 'deduct', 1, -1 ];
        return $cases;
    }

    /** @dataProvider sql_failures */
    public function test_sql_failures_throw_and_rollback( string $stage, bool $missing ): void {
        $db = $GLOBALS['wpdb'];
        $db->rows = $missing ? [] : [ (object) [ 'umeta_id' => 1, 'meta_value' => 20 ] ];
        $before = $db->rows;
        $db->fail = $stage;
        try {
            Wallet::add( 123, 10 );
            $this->fail( 'Expected database failure.' );
        } catch ( RuntimeException $error ) {
            $this->assertEquals( $before, $db->rows );
            if ( ! in_array( $stage, [ 'engine', 'START TRANSACTION' ], true ) ) {
                $this->assertSame( 'ROLLBACK', end( $db->queries ) );
                $this->assertSame( [ [ 123, 'user_meta' ] ], $GLOBALS['wallet_cache_deletions'] );
            }
        }
    }

    public static function sql_failures(): array {
        return array_map( static function ( $stage ) {
            return [ $stage, $stage === 'insert' ];
        }, [ 'engine', 'START TRANSACTION', 'user', 'read', 'update', 'insert', 'COMMIT' ] );
    }

    public function test_non_transactional_tables_are_rejected(): void {
        $GLOBALS['wpdb']->engine = 'MyISAM';
        $this->expectException( RuntimeException::class );
        Wallet::set( 123, 10 );
    }

    public function test_missing_user_is_rejected(): void {
        $GLOBALS['wpdb']->exists = false;
        $this->expectException( RuntimeException::class );
        Wallet::set( 123, 10 );
    }

    public function test_overflow_rolls_back(): void {
        $GLOBALS['wpdb']->rows = [ (object) [ 'umeta_id' => 1, 'meta_value' => PHP_FLOAT_MAX ] ];
        $this->expectException( RuntimeException::class );
        Wallet::add( 123, PHP_FLOAT_MAX );
    }
}
