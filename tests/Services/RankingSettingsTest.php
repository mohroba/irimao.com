<?php

use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Ranking;

class RankingWpdbStub
{
    public string $prefix = 'wp_';

    /** @var array<string,string|null> */
    public array $responses = [];

    private string $last_like = '';

    public function esc_like( $text ): string
    {
        return addcslashes( (string) $text, '%_' );
    }

    public function prepare( $query, ...$args )
    {
        $this->last_like = isset( $args[0] ) ? str_replace( '\\', '', (string) $args[0] ) : '';

        return vsprintf( (string) $query, $args );
    }

    public function get_var( $query )
    {
        return $this->responses[ $this->last_like ] ?? null;
    }
}

class RankingSettingsTest extends TestCase
{
    public function test_sanitize_expiry_days_handles_localized_digits(): void
    {
        $service = new Ranking();
        $method  = new ReflectionMethod(Ranking::class, 'sanitize_expiry_days');
        $method->setAccessible(true);

        $this->assertSame(365, $method->invoke($service, '۳۶۵'));
        $this->assertSame(45, $method->invoke($service, '٤٥'));
        $this->assertSame(12, $method->invoke($service, ['۱۲']));
        $this->assertSame(0, $method->invoke($service, 'abc'));
    }

    public function test_table_exists_checks_database(): void
    {
        $service = new Ranking();
        $method  = new ReflectionMethod(Ranking::class, 'table_exists');
        $method->setAccessible(true);

        $previous_wpdb = $GLOBALS['wpdb'] ?? null;

        $wpdb_stub = new RankingWpdbStub();

        $wpdb_stub->responses = [
            'wp_crm_settings' => 'wp_crm_settings',
        ];

        $GLOBALS['wpdb'] = $wpdb_stub;

        $this->assertTrue( $method->invoke( $service, 'wp_crm_settings' ) );
        $this->assertFalse( $method->invoke( $service, 'wp_missing' ) );

        $GLOBALS['wpdb'] = $previous_wpdb;
    }
}
