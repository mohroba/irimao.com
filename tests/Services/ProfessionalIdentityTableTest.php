<?php

namespace IMAOCustom\Services\Admin {
    function sanitize_text_field( $value ) { return strip_tags( $value ); }
    function wp_unslash( $value ) { return stripslashes( $value ); }
    function wp_roles() { return (object) [ 'roles' => [ 'coach' => [ 'name' => 'مربی' ] ] ]; }
}

namespace {
    use IMAOCustom\Services\Admin\ProfessionalIdentityTable;
    use PHPUnit\Framework\TestCase;

    final class IdentityQueryDatabaseFake {
        public string $usermeta = 'wp_usermeta';
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
    }

    /** Run independently; WordPress stubs are local to this test file. */
    final class ProfessionalIdentityTableTest extends TestCase {
        private $previous_database;

        protected function setUp(): void {
            $this->previous_database = $GLOBALS['wpdb'];
            $GLOBALS['wpdb'] = new IdentityQueryDatabaseFake();
        }

        protected function tearDown(): void { $GLOBALS['wpdb'] = $this->previous_database; }

        private function call( string $method, array $request ): string {
            $reflection = new ReflectionMethod( ProfessionalIdentityTable::class, $method );
            $reflection->setAccessible( true );
            return $reflection->invoke( new ProfessionalIdentityTable(), $request );
        }

        public function test_jalali_and_persian_digits_convert_to_same_day(): void {
            $this->assertSame( '2026-11-10', ProfessionalIdentityTable::parse_date( '۱۴۰۵/۰۸/۱۹' ) );
            $this->assertSame( '2026-11-10', ProfessionalIdentityTable::parse_date( '2026-11-10' ) );
        }

        /** @dataProvider invalid_dates */
        public function test_invalid_dates_are_rejected( string $date ): void {
            $this->expectException( InvalidArgumentException::class );
            ProfessionalIdentityTable::parse_date( $date );
        }

        public function invalid_dates(): array {
            return [ [ '1405/13/01' ], [ '1405/12/30' ], [ '2026/02/30' ], [ 'invalid' ] ];
        }

        public function test_pending_includes_users_without_status_and_never_joins_duplicate_metadata(): void {
            $sql = $this->call( 'where', [ 'status' => 'pending', 'province' => 'THR', 'city' => 'تهران' ] );
            $this->assertStringContainsString( 'NOT EXISTS', $sql );
            $this->assertStringContainsString( "IN ('approved','disapproved')", $sql );
            $this->assertStringContainsString( 'residence_province', $sql );
            $this->assertStringNotContainsString( 'JOIN', $sql );
        }

        public function test_search_matches_all_words_and_normalizes_phone_digits(): void {
            $sql = $this->call( 'where', [ 'search' => [ 'value' => 'علی ۰۹۱۲' ] ] );
            $this->assertStringContainsString( '%0912%', $sql );
            $this->assertStringContainsString( 'national_id', $sql );
            $this->assertSame( 2, substr_count( $sql, 'CAST(u.ID AS CHAR)' ) );
        }

        public function test_role_filters_match_enabled_serialized_capabilities(): void {
            $sql = $this->call( 'where', [ 'role' => 'coach' ] );
            $this->assertStringContainsString( 'wp_capabilities', $sql );
            $this->assertStringContainsString( '"coach";b:1', $sql );
        }

        public function test_unknown_roles_and_reversed_dates_fail_closed(): void {
            $this->expectException( InvalidArgumentException::class );
            $this->call( 'where', [ 'role' => 'unknown' ] );
        }

        public function test_reversed_date_range_is_rejected(): void {
            $this->expectException( InvalidArgumentException::class );
            $this->call( 'where', [ 'date_from' => '1405/08/20', 'date_to' => '1405/08/19' ] );
        }

        public function test_ordering_is_allowlisted(): void {
            $this->assertSame( 'u.user_registered ASC, u.ID DESC', $this->call( 'order', [ 'order' => [ [ 'column' => '4', 'dir' => 'asc' ] ] ] ) );
            $this->assertSame( 'u.ID DESC, u.ID DESC', $this->call( 'order', [ 'sort_column' => '99', 'sort_dir' => 'DROP TABLE' ] ) );
        }
    }
}
