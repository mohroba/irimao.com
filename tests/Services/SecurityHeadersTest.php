<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\SecurityHeaders;

class SecurityHeadersTest extends TestCase {
    protected function setUp(): void {
        header_remove();
        if ( ! function_exists( 'add_action' ) ) {
            function add_action( $hook, $func ) { $GLOBALS['actions'][$hook][] = $func; }
            function do_action( $hook ) { foreach ( $GLOBALS['actions'][$hook] ?? [] as $f ) { $f(); } }
        }
    }

    public function test_headers_are_sent(): void {
        $service = new SecurityHeaders();
        $service->register();
        do_action( 'send_headers' );
        $headers = headers_list();
        $this->assertContains( 'X-Frame-Options: SAMEORIGIN', $headers );
        $this->assertContains( 'X-Content-Type-Options: nosniff', $headers );
        $this->assertContains( 'Referrer-Policy: no-referrer', $headers );
        $this->assertContains( 'Strict-Transport-Security: max-age=31536000; includeSubDomains', $headers );
        $this->assertContains( "Content-Security-Policy: default-src 'self'", $headers );
    }
}
