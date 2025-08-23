<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Services\Assets;

class AssetsTest extends TestCase {
    protected function setUp(): void {
        if ( ! function_exists( 'wp_enqueue_style' ) ) {
            function wp_enqueue_style( $handle, $src = '', $deps = [], $ver = '' ) {}
            function wp_enqueue_script( $handle, $src = '', $deps = [], $ver = '', $in_footer = false ) {}
            function wp_add_inline_script( $handle, $data ) { $GLOBALS['inline_scripts'][$handle] = $data; }
            function plugin_dir_url( $file ) { return '/'; }
            function wp_localize_script( $handle, $name, $data ) {}
        }
        $GLOBALS['inline_scripts'] = [];
    }

    public function test_sweetalert_inline_script_registered(): void {
        $assets = new Assets();
        $assets->enqueue_frontend();
        $this->assertArrayHasKey( 'imao-sweetalert2', $GLOBALS['inline_scripts'] );
        $this->assertStringContainsString( 'Swal.fire', $GLOBALS['inline_scripts']['imao-sweetalert2'] );
    }
}
