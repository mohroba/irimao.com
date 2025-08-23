<?php
namespace IMAOCustom\Services;

class SecurityHeaders {
    public function register(): void {
        add_action('send_headers', [ $this, 'add_headers' ]);
    }

    public function add_headers(): void {
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        header("Content-Security-Policy: default-src 'self'");
    }
}
