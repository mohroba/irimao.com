<?php

namespace IMAOCustom\Services {
    function plugin_dir_url( $path ) { return '/plugin/'; }
    function wp_enqueue_style( $handle, ...$args ) { $GLOBALS['identity_assets'][] = $handle; }
    function wp_enqueue_script( $handle, ...$args ) { $GLOBALS['identity_assets'][] = $handle; }
    function wp_add_inline_script( ...$args ) {}
    function is_wc_endpoint_url( $endpoint ) { return false; }
}

namespace {
    use IMAOCustom\Services\Assets;
    use PHPUnit\Framework\TestCase;

    final class IdentityAssetsTest extends TestCase {
        public function test_custom_endpoint_is_lean_even_without_woocommerce_query_registration(): void {
            $previous = $GLOBALS['wp'] ?? null;
            $GLOBALS['wp'] = (object) [ 'query_vars' => [ 'identity-professional' => '' ] ];
            $GLOBALS['identity_assets'] = [];
            try {
                ( new Assets() )->enqueue_frontend();
                $this->assertContains( 'imao-identity-professional', $GLOBALS['identity_assets'] );
                $this->assertContains( 'imao-sweetalert2', $GLOBALS['identity_assets'] );
                $this->assertNotContains( 'imao-edit-basic-info', $GLOBALS['identity_assets'] );
                $this->assertNotContains( 'imao-select2', $GLOBALS['identity_assets'] );
                $this->assertNotContains( 'imao-club-register', $GLOBALS['identity_assets'] );
                $this->assertNotContains( 'imao-monthly-classes', $GLOBALS['identity_assets'] );
            } finally {
                $GLOBALS['wp'] = $previous;
            }
        }
    }
}
