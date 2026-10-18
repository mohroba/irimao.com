<?php
/** Run directly: php tests/fixtures/monthly-tuition.php. No WordPress bootstrap or network. */
namespace IMAOCustom\Services {
    function wc_get_order( $id ) { return $GLOBALS['tuition_order']; }
    function wp_timezone() { return new \DateTimeZone( 'Asia/Tehran' ); }
    function current_time( $format ) { return $format === 'mysql' ? '2026-10-18 12:00:00' : '2026-10-18'; }
    function wp_json_encode( $value ) { return json_encode( $value, JSON_THROW_ON_ERROR ); }
    function maybe_serialize( $value ) { return is_array( $value ) ? serialize( $value ) : $value; }
    function maybe_unserialize( $value ) { return is_string( $value ) && strpos( $value, 'a:' ) === 0 ? unserialize( $value, [ 'allowed_classes' => false ] ) : $value; }
    function wp_cache_delete( $id, $group ) {}
    function wp_next_scheduled( $hook, $args ) { return false; }
    function wp_schedule_single_event( $time, $hook, $args ) { $GLOBALS['tuition_retries'][] = [ 'time' => $time, 'hook' => $hook, 'args' => $args ]; }
    function wc_get_logger() { return new class { public function error( $message, $context ) { $GLOBALS['tuition_errors'][] = $message; } }; }
    // Reads of mutable relationships during settlement/refund are deliberately observable.
    function get_user_meta( $id, $key, $single = true ) { ++$GLOBALS['tuition_live_reads']; return $GLOBALS['tuition_live'][$key] ?? ''; }
    function get_post_meta( $id, $key, $single = true ) { ++$GLOBALS['tuition_live_reads']; return $GLOBALS['tuition_live'][$key] ?? ''; }
    function get_post( $id ) { ++$GLOBALS['tuition_live_reads']; return (object) [ 'post_author' => 93 ]; }
}

namespace MonthlyTuitionFixture {
    require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

    use IMAOCustom\Services\MonthlyTuition;
    use RuntimeException;

    final class FakeWpdb {
        public string $last_error = '';
        public $failReadAt = 0;
        public $reads = 0;
        public $prefix = 'fixture_';
        public $users = 'fixture_users';
        public $usermeta = 'fixture_usermeta';
        public $state = [ 'ledger' => [], 'meta' => [], 'users' => [ 11, 12, 13, 91, 92, 93 ] ];
        public $failAt = 0;
        public $walletWrites = 0;
        public $rollbacks = 0;
        private $snapshot;

        public function prepare( $sql, ...$args ) {
            if ( count( $args ) === 1 && is_array( $args[0] ) ) { $args = $args[0]; }
            $index = 0;
            $result = preg_replace_callback( '/%[dsf]/', static function ( $match ) use ( $args, &$index ) {
                if ( ! array_key_exists( $index, $args ) ) { throw new RuntimeException( 'Missing SQL argument' ); }
                $value = $args[$index++];
                return $match[0] === '%s' ? "'" . str_replace( "'", "''", (string) $value ) . "'" : (string) ( $match[0] === '%d' ? (int) $value : (float) $value );
            }, $sql );
            if ( $index !== count( $args ) ) { throw new RuntimeException( 'Unused SQL arguments' ); }
            return $result;
        }

        public function query( $sql ) {
            switch ( $sql ) {
                case 'START TRANSACTION':
                    if ( $this->snapshot !== null ) { throw new RuntimeException( 'Nested transaction' ); }
                    $this->snapshot = $this->state;
                    return 1;
                case 'COMMIT': $this->snapshot = null; return 1;
                case 'ROLLBACK':
                    if ( $this->snapshot === null ) { throw new RuntimeException( 'Rollback without transaction' ); }
                    $this->state = $this->snapshot;
                    $this->snapshot = null;
                    ++$this->rollbacks;
                    return 1;
                default: throw new RuntimeException( 'Unsupported SQL: ' . $sql );
            }
        }

        public function get_var( $sql ) {
            $this->last_error = '';
            if ( preg_match( "/^SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE\(\) AND TABLE_NAME='(fixture_users|fixture_usermeta|fixture_imao_class_tuition)'$/", $sql ) ) { return 'InnoDB'; }
            if ( preg_match( '/^SELECT ID FROM fixture_users WHERE ID=(\d+) FOR UPDATE$/', $sql, $m ) ) { return in_array( (int) $m[1], $this->state['users'], true ) ? $m[1] : null; }
            if ( preg_match( '/^SELECT id FROM fixture_imao_class_tuition WHERE order_item_id=(\d+) FOR UPDATE$/', $sql, $m ) ) {
                foreach ( $this->state['ledger'] as $row ) { if ( $row['order_item_id'] === (int) $m[1] ) { return $row['id']; } }
                return null;
            }
            throw new RuntimeException( 'Unsupported scalar SQL: ' . $sql );
        }

        public function get_results( $sql ) {
            $this->last_error = '';
            if ( preg_match( '/^SELECT \* FROM fixture_imao_class_tuition WHERE order_id=(\d+)$/', $sql, $m ) ) {
                return array_values( array_map( static function ( $row ) { return (object) $row; }, array_filter( $this->state['ledger'], static function ( $row ) use ( $m ) { return $row['order_id'] === (int) $m[1]; } ) ) );
            }
            if ( preg_match( "/^SELECT umeta_id,meta_value FROM fixture_usermeta WHERE user_id=(\\d+) AND meta_key='([^']+)' ORDER BY umeta_id FOR UPDATE$/", $sql, $m ) ) {
                if ( ++$this->reads === $this->failReadAt ) { $this->last_error = 'Injected wallet SELECT failure'; return null; }
                return array_values( array_map( static function ( $row ) { return (object) $row; }, array_filter( $this->state['meta'], static function ( $row ) use ( $m ) { return $row['user_id'] === (int) $m[1] && $row['meta_key'] === $m[2]; } ) ) );
            }
            throw new RuntimeException( 'Unsupported rows SQL: ' . $sql );
        }

        public function get_row( $sql ) {
            $this->last_error = '';
            if ( ++$this->reads === $this->failReadAt ) { $this->last_error = 'Injected locked ledger SELECT failure'; return null; }
            if ( preg_match( '/^SELECT \* FROM fixture_imao_class_tuition WHERE id=(\d+) FOR UPDATE$/', $sql, $m ) ) { return isset( $this->state['ledger'][(int) $m[1]] ) ? (object) $this->state['ledger'][(int) $m[1]] : null; }
            throw new RuntimeException( 'Unsupported row SQL: ' . $sql );
        }

        private function failWallet( $table ) {
            if ( $this->snapshot === null ) { throw new RuntimeException( 'Write outside transaction' ); }
            return $table === $this->usermeta && ++$this->walletWrites === $this->failAt;
        }

        public function insert( $table, $data ) {
            if ( $this->failWallet( $table ) ) { return false; }
            if ( $table === $this->prefix . 'imao_class_tuition' ) {
                foreach ( $this->state['ledger'] as $row ) { if ( $row['order_item_id'] === $data['order_item_id'] ) { return false; } }
                $data['id'] = count( $this->state['ledger'] ) + 1;
                $this->state['ledger'][$data['id']] = $data;
            } elseif ( $table === $this->usermeta ) {
                $data['umeta_id'] = count( $this->state['meta'] ) + 1;
                $this->state['meta'][] = $data;
            } else { throw new RuntimeException( 'Unsupported insert table' ); }
            return 1;
        }

        public function update( $table, $data, $where ) {
            if ( $this->failWallet( $table ) ) { return false; }
            $key = $table === $this->usermeta ? 'meta' : ( $table === $this->prefix . 'imao_class_tuition' ? 'ledger' : null );
            if ( $key === null ) { throw new RuntimeException( 'Unsupported update table' ); }
            $count = 0;
            foreach ( $this->state[$key] as &$row ) {
                if ( array_intersect_assoc( $where, $row ) === $where ) { $row = array_merge( $row, $data ); ++$count; }
            }
            return $count;
        }

        public function value( $id, $key ) {
            foreach ( $this->state['meta'] as $row ) { if ( $row['user_id'] === $id && $row['meta_key'] === $key ) { return \IMAOCustom\Services\maybe_unserialize( $row['meta_value'] ); } }
            return $key === '_wallet_balance' ? 0 : [];
        }
    }

    final class Item {
        public $snapshot;
        public function __construct( $shared ) { $this->snapshot = [ 'class_id' => 50, 'user_id' => 7, 'club_id' => 60, 'recipients' => [ 'referrer' => 11, 'coach' => 12, 'club' => $shared ? 12 : 13 ], 'shares' => [ 'referrer' => 20, 'coach' => 30, 'club' => 50 ] ]; }
        public function get_meta( $key, $single ) { return $key === MonthlyTuition::ITEM_SNAPSHOT ? $this->snapshot : ''; }
        public function get_id() { return 101; }
        public function get_total() { return 100.03; }
    }
    final class Order {
        public $item;
        public $refund = 0;
        public $status = 'completed';
        public $notes = [];
        public function __construct( $shared ) { $this->item = new Item( $shared ); }
        public function get_id() { return 100; }
        public function get_customer_id() { return 7; }
        public function is_paid() { return $this->status === 'completed'; }
        public function get_items() { return [ $this->item ]; }
        public function get_date_paid() { return new \DateTimeImmutable( '2026-10-18 12:00:00', \IMAOCustom\Services\wp_timezone() ); }
        public function add_order_note( $note ) { $this->notes[] = $note; }
        public function has_status( $status ) { return $this->status === $status; }
        public function get_total_refunded_for_item( $id ) { return -$this->refund; }
    }

    function check( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
    function setup( $seed = false, $shared = false ) {
        $GLOBALS['wpdb'] = new FakeWpdb();
        $GLOBALS['tuition_order'] = new Order( $shared );
        $GLOBALS['tuition_errors'] = $GLOBALS['tuition_retries'] = [];
        $GLOBALS['tuition_live_reads'] = 0;
        $GLOBALS['tuition_live'] = [ 'referrer_id' => 91, 'coach_id' => 92, '_class_coach_id' => 92, '_class_club_id' => 93, '_class_shares' => [ 'referrer' => 80, 'coach' => 10, 'club' => 10 ] ];
        if ( $seed ) {
            foreach ( [ 11, 12, 13 ] as $id ) {
                foreach ( [ '_wallet_balance' => 50, 'crm_wallet_log' => serialize( [ [ 'note' => 'Existing entry', 'amount' => 50 ] ] ) ] as $key => $value ) {
                    $GLOBALS['wpdb']->state['meta'][] = [ 'umeta_id' => count( $GLOBALS['wpdb']->state['meta'] ) + 1, 'user_id' => $id, 'meta_key' => $key, 'meta_value' => $value ];
                }
            }
        }
        return new MonthlyTuition();
    }

    function assertBalances( array $expected, $base = 0 ) {
        foreach ( $expected as $id => $amount ) { check( abs( (float) $GLOBALS['wpdb']->value( $id, '_wallet_balance' ) - $base - $amount ) < 0.00001, 'Unexpected balance for ' . $id ); }
        foreach ( [ 91, 92, 93 ] as $id ) { check( $GLOBALS['wpdb']->value( $id, '_wallet_balance' ) === 0, 'Mutable recipient received funds' ); }
        check( $GLOBALS['tuition_live_reads'] === 0, 'Settlement/refund read mutable relationships' );
    }

    $cases = 0;
    $failures = [];
    function run( $name, callable $test ) {
        global $cases, $failures;
        ++$cases;
        try { $test(); echo "PASS {$name}\n"; } catch ( \Throwable $error ) { $failures[] = $name . ': ' . $error->getMessage(); echo 'FAIL ' . end( $failures ) . "\n"; }
    }

    foreach ( [ false, true ] as $shared ) {
        run( 'duplicate payment and repeated refunds; shared recipient=' . (int) $shared, static function () use ( $shared ) {
            $service = setup( false, $shared );
            $db = $GLOBALS['wpdb'];
            $order = $GLOBALS['tuition_order'];
            $service->process_order( 100 );
            check( count( $db->state['ledger'] ) === 1 && ! $GLOBALS['tuition_errors'], 'Initial settlement failed' );
            $original = $order->item->snapshot;
            $stored = json_decode( $db->state['ledger'][1]['snapshot'], true, 512, JSON_THROW_ON_ERROR );
            check( $stored['recipients'] === $original['recipients'] && $stored['shares'] === $original['shares'], 'Ledger lost original snapshot' );
            assertBalances( $shared ? [ 11 => 20, 12 => 80.03 ] : [ 11 => 20, 12 => 30, 13 => 50.03 ] );
            check( count( $db->value( 11, 'crm_wallet_log' ) ) === 1 && count( $db->value( 12, 'crm_wallet_log' ) ) === ( $shared ? 2 : 1 ), 'Missing role wallet logs' );
            $paid = $db->state;
            $service->process_order( 100 );
            check( $paid === $db->state, 'Duplicate callback changed ledger, balances or logs' );
            // Also change item metadata: refunds must use the persisted ledger snapshot.
            $order->item->snapshot['recipients'] = [ 'referrer' => 91, 'coach' => 92, 'club' => 93 ];
            $order->item->snapshot['shares'] = [ 'referrer' => 80, 'coach' => 10, 'club' => 10 ];
            $GLOBALS['tuition_live']['coach_id'] = 93;
            $GLOBALS['tuition_live']['_class_shares'] = $order->item->snapshot['shares'];
            foreach ( [ 25.01, 60.02, 100.03 ] as $refund ) {
                $order->refund = $refund;
                $service->reverse_order( 100 );
                $parts = MonthlyTuition::split_amount( round( 100.03 - $refund, 2 ), $original['shares'] );
                assertBalances( $shared ? [ 11 => $parts['referrer'], 12 => $parts['coach'] + $parts['club'] ] : [ 11 => $parts['referrer'], 12 => $parts['coach'], 13 => $parts['club'] ] );
                $state = $db->state;
                $service->reverse_order( 100 );
                $service->process_order( 100 );
                check( $state === $db->state, 'Repeated refund/payment changed financial state' );
                check( $db->state['ledger'][1]['status'] === ( $refund === 100.03 ? 'reversed' : 'partial_refund' ), 'Incorrect refund status' );
            }
            $order->status = 'refunded';
            $service->reverse_order( 100 );
            check( $db->state === $state, 'Full status callback changed fully refunded state' );
        } );
    }

    run( 'failed first settlement then partial refund then retry credits net only', static function () {
        $service = setup();
        $db = $GLOBALS['wpdb'];
        $order = $GLOBALS['tuition_order'];
        $before = $db->state;
        $db->failAt = 4;
        $service->process_order( 100 );
        check( $db->state === $before && $db->rollbacks === 1, 'First settlement was not fully rolled back' );
        check( count( $GLOBALS['tuition_retries'] ) === 1, 'Settlement retry not scheduled' );
        $order->refund = 25.01;
        $db->failAt = 0;
        $service->process_order( 100, 1 );
        // Gross 100.03 less 25.01 refunded: only 75.02 may reach wallets.
        assertBalances( [ 11 => 15, 12 => 22.5, 13 => 37.52 ] );
        check( count( $db->state['ledger'] ) === 1 && $db->state['ledger'][1]['status'] === 'partial_refund', 'Retry ledger status incorrect' );
        $snapshot = json_decode( $db->state['ledger'][1]['snapshot'], true, 512, JSON_THROW_ON_ERROR );
        check( $snapshot['refunded_amount'] === 25.01 && abs( array_sum( $snapshot['credited'] ) - 75.02 ) < 0.00001, 'Retry snapshot is not net of refund' );
        foreach ( [ 11 => 15, 12 => 22.5, 13 => 37.52 ] as $id => $net ) {
            $log = $db->value( $id, 'crm_wallet_log' );
            check( count( $log ) === 1 && abs( $log[0]['amount'] - $net ) < 0.00001, 'Retry logged gross credit or a redundant reversal' );
        }
        $netState = $db->state;
        $service->process_order( 100, 1 );
        $service->reverse_order( 100 );
        $service->reverse_order( 100 );
        check( $netState === $db->state, 'Repeated callbacks changed net settlement' );
    } );

    foreach ( [ false, true ] as $seed ) {
        foreach ( range( 1, 6 ) as $position ) {
            run( 'payment rollback wallet write ' . $position . '; existing meta=' . (int) $seed, static function () use ( $seed, $position ) {
                $service = setup( $seed );
                $db = $GLOBALS['wpdb'];
                $before = $db->state;
                $db->failAt = $position;
                $service->process_order( 100 );
                check( $db->state === $before && $db->rollbacks === 1, 'Payment did not fully roll back' );
                check( count( $GLOBALS['tuition_errors'] ) === 1 && count( $GLOBALS['tuition_retries'] ) === 1, 'Failure was not reported/scheduled' );
                $db->failAt = 0;
                $service->process_order( 100 );
                assertBalances( [ 11 => 20, 12 => 30, 13 => 50.03 ], $seed ? 50 : 0 );
                check( count( $db->state['ledger'] ) === 1, 'Retry missing ledger' );
            } );
        }
    }

    foreach ( [ 25.01, 100.03 ] as $refund ) {
        foreach ( range( 1, 6 ) as $position ) {
            run( 'refund rollback wallet write ' . $position . '; amount=' . $refund, static function () use ( $refund, $position ) {
                $service = setup();
                $db = $GLOBALS['wpdb'];
                $service->process_order( 100 );
                $before = $db->state;
                $GLOBALS['tuition_order']->refund = $refund;
                if ( $refund === 100.03 ) { $GLOBALS['tuition_order']->status = 'refunded'; }
                $db->failAt = $db->walletWrites + $position;
                $service->reverse_order( 100 );
                check( $db->state === $before && $db->rollbacks === 1, 'Refund did not fully roll back' );
                check( count( $GLOBALS['tuition_errors'] ) === 1, 'Refund failure not reported' );
                check( count( $GLOBALS['tuition_retries'] ) === 1, 'Refund failure did not schedule one retry' );
                $retry = $GLOBALS['tuition_retries'][0];
                check( $retry['hook'] === 'imao_retry_monthly_tuition_refund' && $retry['args'] === [ 100, 1 ], 'Incorrect refund retry hook or arguments' );
                check( abs( $retry['time'] - time() - 300 ) <= 2, 'Incorrect refund retry delay' );
                $db->failAt = 0;
                $service->reverse_order( 100 );
                check( $db->state['ledger'][1]['status'] === ( $refund === 100.03 ? 'reversed' : 'partial_refund' ), 'Refund retry failed' );
                $after = $db->state;
                $service->reverse_order( 100 );
                check( $after === $db->state, 'Refund retry not idempotent' );
            } );
        }
    }
    foreach ( [ 'payment', 'refund' ] as $operation ) {
        foreach ( range( 1, $operation === 'payment' ? 6 : 7 ) as $position ) {
            run( $operation . ' SQL read failure ' . $position, static function () use ( $operation, $position ) {
                $service = setup( true );
                $db = $GLOBALS['wpdb'];
                if ( $operation === 'refund' ) {
                    $service->process_order( 100 );
                    $GLOBALS['tuition_order']->refund = 25.01;
                }
                $before = $db->state;
                $db->failReadAt = $db->reads + $position;
                if ( $operation === 'payment' ) { $service->process_order( 100 ); }
                else { $service->reverse_order( 100 ); }
                check( $db->state === $before && $db->rollbacks === 1, 'SQL read failure did not fully roll back' );
                check( count( $GLOBALS['tuition_errors'] ) === 1 && count( $GLOBALS['tuition_retries'] ) === 1, 'SQL read failure not reported/scheduled' );
                $retry = $GLOBALS['tuition_retries'][0];
                check( $retry['hook'] === ( $operation === 'payment' ? 'imao_retry_monthly_tuition' : 'imao_retry_monthly_tuition_refund' ), 'SQL read failure scheduled wrong retry' );
                $db->failReadAt = 0;
                if ( $operation === 'payment' ) { $service->process_order( 100, 1 ); }
                else { $service->reverse_order( 100, 1 ); }
                assertBalances( $operation === 'payment' ? [ 11 => 20, 12 => 30, 13 => 50.03 ] : [ 11 => 15, 12 => 22.5, 13 => 37.52 ], 50 );
            } );
        }
    }
    echo count( $failures ) . " failures / {$cases} scenarios\n";
    exit( $failures ? 1 : 0 );
}
