<?php

namespace IMAOCustom\Services\Admin {
    if ( ! function_exists( __NAMESPACE__ . '\\sanitize_text_field' ) ) {
        function sanitize_text_field( $value ) { return strip_tags( $value ); }
        function wp_unslash( $value ) { return stripslashes( $value ); }
        function wp_roles() { return (object) [ 'roles' => [ 'coach' => [ 'name' => 'مربی' ] ] ]; }
    }
    function update_meta_cache( $type, $ids ) { $GLOBALS['directory_cached_ids'] = $ids; }
}

namespace {
    use IMAOCustom\Services\Admin\BasicInfoTable;
    use PHPUnit\Framework\TestCase;

    final class DirectoryQueryDatabaseFake {
        public string $users = 'wp_users';
        public string $usermeta = 'wp_usermeta';
        public array $queries = [];
        public array $ids = [];
        public bool $export_fixture = false;
        public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
        public function get_blog_prefix() { return 'wp_'; }
        public function prepare( $query, ...$values ) {
            foreach ( $values as $value ) {
                $query = preg_replace_callback( '/%[sd]/', static function ( $match ) use ( $value ) {
                    return $match[0] === '%d' ? (string) (int) $value : "'" . str_replace( "'", "''", $value ) . "'";
                }, $query, 1 );
            }
            return $query;
        }
        public function get_col( $sql ) {
            $this->queries[] = $sql;
            if ( $this->export_fixture ) {
                preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $sql, $parts );
                return array_slice( range( 1, 401 ), (int) $parts[2], (int) $parts[1] );
            }
            return $this->ids;
        }
        public function get_var( $sql ) { $this->queries[] = $sql; return strpos( $sql, ' WHERE ' ) === false ? 500 : 12; }
    }

    /** Isolated query tests: no WordPress database or external services. */
    final class BasicInfoTableTest extends TestCase {
        private $previous_database;
        private DirectoryQueryDatabaseFake $database;

        protected function setUp(): void {
            $this->previous_database = $GLOBALS['wpdb'];
            $this->database = new DirectoryQueryDatabaseFake();
            $GLOBALS['wpdb'] = $this->database;
        }

        protected function tearDown(): void {
            $GLOBALS['wpdb'] = $this->previous_database;
            unset( $GLOBALS['test_users_callable'], $GLOBALS['directory_cached_ids'] );
            unset( $GLOBALS['test_user_meta'][991] );
        }

        private function call( string $method, array $request, bool $wallet = false ): string {
            $reflection = new ReflectionMethod( BasicInfoTable::class, $method );
            $reflection->setAccessible( true );
            return $reflection->invoke( new BasicInfoTable( $wallet ), $request );
        }

        public function test_pages_are_bounded_and_counts_are_distinct(): void {
            $page = ( new BasicInfoTable() )->page( [ 'start' => 50, 'length' => 100000, 'draw' => 3, 'search_text' => 'علی' ] );
            $this->assertSame( 500, $page['recordsTotal'] );
            $this->assertSame( 12, $page['recordsFiltered'] );
            $this->assertSame( 3, $page['draw'] );
            $this->assertStringContainsString( 'LIMIT 100 OFFSET 50', end( $this->database->queries ) );
            $this->assertSame( [], $page['users'] );
        }

        public function test_all_rows_request_cannot_disable_pagination(): void {
            ( new BasicInfoTable( true ) )->page( [ 'length' => -1, 'start' => -10 ] );
            $this->assertStringContainsString( 'LIMIT 1 OFFSET 0', end( $this->database->queries ) );
        }

        public function test_only_selected_ids_are_hydrated_and_metadata_is_primed(): void {
            $this->database->ids = [ 7, 9 ];
            $GLOBALS['test_users_callable'] = function ( $args ) {
                $this->assertSame( [ 7, 9 ], $args['include'] );
                $this->assertSame( 25, $args['number'] );
                $this->assertSame( 'include', $args['orderby'] );
                $this->assertFalse( $args['count_total'] );
                return [ (object) [ 'ID' => 7 ], (object) [ 'ID' => 9 ] ];
            };
            $page = ( new BasicInfoTable() )->page( [] );
            $this->assertCount( 2, $page['users'] );
            $this->assertSame( [ 7, 9 ], $GLOBALS['directory_cached_ids'] );
        }

        public function test_filters_include_user_scope_role_and_inclusive_jalali_dates(): void {
            $sql = $this->call( 'where', [ 'user_id' => 27, 'role' => 'coach', 'status' => 'active', 'province' => 'THR', 'city' => 'تهران', 'date_from' => '۱۴۰۵/۰۸/۱۹', 'date_to' => '1405/08/20' ] );
            foreach ( [ 'u.ID=27', 'wp_capabilities', '"coach";b:1', 'residence_city', 'residence_province', "IN ('','0')", '2026-11-10 00:00:00', '2026-11-11 23:59:59' ] as $part ) {
                $this->assertStringContainsString( $part, $sql );
            }
            $this->assertStringNotContainsString( 'JOIN', $sql );
        }

        public function test_wallet_sort_includes_missing_and_duplicate_balance_metadata(): void {
            $sql = $this->call( 'order', [ 'order' => [ [ 'column' => 7, 'dir' => 'asc' ] ] ], true );
            foreach ( [ 'COALESCE', '_wallet_balance', 'ORDER BY m.umeta_id LIMIT 1', 'DECIMAL(24,6)', ' ASC, u.ID DESC' ] as $part ) {
                $this->assertStringContainsString( $part, $sql );
            }
        }

        public function test_search_matches_each_word_and_bank_and_profile_fields(): void {
            $sql = $this->call( 'where', [ 'search' => [ 'value' => 'علی ۰۹۱۲' ] ] );
            $this->assertSame( 2, substr_count( $sql, 'CAST(u.ID AS CHAR)' ) );
            foreach ( [ '%0912%', 'national_id', 'card_number', 'iban', 'billing_email', 'first_name_en' ] as $part ) {
                $this->assertStringContainsString( $part, $sql );
            }
        }

        public function test_export_and_grid_sort_requests_match(): void {
            $this->assertSame(
                $this->call( 'order', [ 'order' => [ [ 'column' => 3, 'dir' => 'asc' ] ] ] ),
                $this->call( 'order', [ 'sort_column' => 3, 'sort_dir' => 'asc' ] )
            );
            $this->assertSame( $this->call( 'where', [ 'search_text' => 'علی' ] ), $this->call( 'where', [ 'search' => [ 'value' => 'علی' ] ] ) );
            $this->assertSame( 'u.ID DESC, u.ID DESC', $this->call( 'order', [ 'sort_column' => 999, 'sort_dir' => 'DROP TABLE' ] ) );
        }

        /** @dataProvider invalid_filters */
        public function test_invalid_filters_fail_closed( array $request ): void {
            $this->expectException( InvalidArgumentException::class );
            $this->call( 'where', $request );
        }

        public function invalid_filters(): array {
            return [ [ [ 'role' => 'unknown' ] ], [ [ 'status' => 'unknown' ] ], [ [ 'date_from' => '1405/13/01' ] ], [ [ 'date_from' => '1405/08/20', 'date_to' => '1405/08/19' ] ], [ [ 'birth_from' => '1405/08/20', 'birth_to' => '1405/08/19' ] ] ];
        }

        public function test_birth_filters_normalize_digits_and_unpadded_dates(): void {
            $sql = $this->call( 'where', [ 'birth_from' => '۱۳۷۵/۱/۱', 'birth_to' => '1375/12/29' ] );
            foreach ( [ 'birth_date', 'LPAD', 'REPLACE', '1375/01/01', '1375/12/29' ] as $part ) {
                $this->assertStringContainsString( $part, $sql );
            }
        }

        public function test_export_reads_every_match_in_bounded_batches(): void {
            $this->database->export_fixture = true;
            $GLOBALS['test_users_callable'] = static function ( $args ) {
                return array_map( static function ( $id ) { return (object) [ 'ID' => $id ]; }, $args['include'] );
            };
            $method = new ReflectionMethod( BasicInfoTable::class, 'export_batches' );
            $method->setAccessible( true );
            $batches = iterator_to_array( $method->invoke( new BasicInfoTable(), '1=1', 'u.ID ASC' ) );
            $this->assertSame( [ 0, 200, 400 ], array_keys( $batches ) );
            $this->assertSame( [ 200, 200, 1 ], array_map( 'count', array_values( $batches ) ) );
            $this->assertSame( 401, $batches[400][0]->ID );
            foreach ( $this->database->queries as $query ) {
                $this->assertStringContainsString( 'LIMIT 200 OFFSET', $query );
            }
        }

        public function test_wallet_exports_preserve_full_bank_fields_and_omit_forms(): void {
            $GLOBALS['test_user_meta'][991] = [
                'first_name_fa' => 'علی', 'last_name_fa' => 'رضایی',
                'card_number' => '6037991234567890', 'iban' => 'IR123456789012345678901234',
                '_wallet_balance' => '1200.50', 'imao_banned' => '',
            ];
            $users = [ (object) [ 'ID' => 991, 'display_name' => 'علی رضایی', 'user_registered' => '2026-11-10 12:00:00' ] ];
            $rows = ( new \IMAOCustom\Services\Endpoints\Wallet() )->wallet_manager_rows( $users, 0, true );
            $this->assertCount( 10, $rows[0] );
            $this->assertSame( '6037991234567890', $rows[0][8] );
            $this->assertSame( 'IR123456789012345678901234', $rows[0][9] );
            $this->assertSame( '1200.5', $rows[0][7] );
            $this->assertStringNotContainsString( '<form', implode( '', $rows[0] ) );
        }
    }
}
