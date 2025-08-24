<?php
use PHPUnit\Framework\TestCase;
use IMAOCustom\Helpers\Bootstrap;

if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src, $deps = [], $ver = null) {
        BootstrapTest::$styles[$handle] = compact('handle','src','deps','ver');
    }
}
if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script($handle, $src, $deps = [], $ver = null, $in_footer = false) {
        BootstrapTest::$scripts[$handle] = compact('handle','src','deps','ver','in_footer');
    }
}

class BootstrapTest extends TestCase {
    public static $styles = [];
    public static $scripts = [];

    protected function setUp(): void {
        self::$styles = [];
        self::$scripts = [];
    }

    public function test_enqueue_adds_bootstrap_assets(): void {
        Bootstrap::enqueue();
        $this->assertArrayHasKey('bootstrap-css', self::$styles);
        $this->assertArrayHasKey('bootstrap-js', self::$scripts);
        $this->assertStringContainsString('bootstrap.min.css', self::$styles['bootstrap-css']['src']);
        $this->assertStringContainsString('bootstrap.bundle.min.js', self::$scripts['bootstrap-js']['src']);
    }
}
