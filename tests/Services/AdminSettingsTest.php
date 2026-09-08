<?php

use IMAOCustom\Services\Admin\Settings;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../includes/Services/Admin/Settings.php';

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return $GLOBALS['test_admin_options'][$name] ?? $default;
    }
}

class AdminSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['test_admin_options'][Settings::OPTION_ADD_TO_CART_MESSAGE] = '0';
        $GLOBALS['filters']['wc_add_to_cart_message_html'] = [];
    }

    public function test_message_is_disabled_by_default(): void
    {
        $settings = new Settings();

        $this->assertFalse($settings->is_add_to_cart_message_enabled());
        $this->assertNull($settings->disable_add_to_cart_message('Added'));
    }

    public function test_checkbox_sanitization(): void
    {
        $settings = new Settings();

        $this->assertSame('1', $settings->sanitize_checkbox('1'));
        $this->assertSame('0', $settings->sanitize_checkbox(''));
    }

    public function test_enabled_option_is_detected(): void
    {
        $GLOBALS['test_admin_options'][Settings::OPTION_ADD_TO_CART_MESSAGE] = '1';

        $this->assertTrue((new Settings())->is_add_to_cart_message_enabled());
    }
}
