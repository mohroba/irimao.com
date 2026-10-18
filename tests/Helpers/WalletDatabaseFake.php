<?php

/** Minimal wpdb double for wallet tests; no production fallback. */
final class WalletDatabaseFake {
    public $users = 'wp_users';
    public $usermeta = 'wp_usermeta';
    public $last_error = '';
    public $engine = 'InnoDB';
    public $exists = true;
    public $fail = '';
    public $queries = [];
    public $rows = null;
    public $on_lock;
    private $snapshot;
    private $user_id;

    public function prepare( $sql, ...$args ) {
        foreach ( $args as $arg ) {
            $sql = preg_replace( '/%[ds]/', is_int( $arg ) ? (string) $arg : "'" . $arg . "'", $sql, 1 );
        }
        return $sql;
    }

    private function record( $stage, $sql ) {
        $this->queries[] = $sql;
        $this->last_error = $this->fail === $stage ? 'Simulated SQL failure' : '';
        return $this->last_error === '';
    }

    public function get_var( $sql ) {
        if ( strpos( $sql, 'SELECT ENGINE' ) === 0 ) {
            return $this->record( 'engine', $sql ) ? $this->engine : null;
        }
        if ( ! $this->record( 'user', $sql ) ) {
            return null;
        }
        preg_match( '/WHERE ID=(\d+) FOR UPDATE/', $sql, $match );
        $this->user_id = (int) $match[1];
        if ( $this->on_lock ) {
            ( $this->on_lock )( $this );
        }
        return $this->exists ? $this->user_id : null;
    }

    public function get_results( $sql ) {
        if ( ! $this->record( 'read', $sql ) ) {
            return null;
        }
        if ( $this->rows !== null ) {
            return $this->rows;
        }
        $value = $GLOBALS['test_user_meta'][ $this->user_id ]['_wallet_balance'] ?? null;
        return $value === null ? [] : [ (object) [ 'umeta_id' => 1, 'meta_value' => $value ] ];
    }

    public function query( $sql ) {
        if ( ! $this->record( $sql, $sql ) ) {
            return false;
        }
        if ( $sql === 'START TRANSACTION' ) {
            $this->snapshot = [ $this->rows, $GLOBALS['test_user_meta'] ?? [] ];
        } elseif ( $sql === 'ROLLBACK' ) {
            [ $this->rows, $GLOBALS['test_user_meta'] ] = $this->snapshot;
        }
        return 0;
    }

    public function update( $table, $data, $where, $formats = [], $where_formats = [] ) {
        return $this->write( 'update', $data, $where );
    }

    public function insert( $table, $data, $formats = [] ) {
        return $this->write( 'insert', $data, $data );
    }

    private function write( $stage, $data, $where ) {
        if ( ! $this->record( $stage, $stage ) ) {
            return false;
        }
        if ( $this->rows !== null ) {
            $count = max( 1, count( $this->rows ) );
            $this->rows = array_map( static function ( $id ) use ( $data ) {
                return (object) [ 'umeta_id' => $id, 'meta_value' => $data['meta_value'] ];
            }, range( 1, $count ) );
        } else {
            $GLOBALS['test_user_meta'][ $where['user_id'] ][ $where['meta_key'] ] = $data['meta_value'];
        }
        return 0; // Zero affected rows is also a successful wpdb result.
    }
}

if ( ! function_exists( 'wp_cache_delete' ) ) {
    function wp_cache_delete( $key, $group ) {
        $GLOBALS['wallet_cache_deletions'][] = [ $key, $group ];
        return true;
    }
}
