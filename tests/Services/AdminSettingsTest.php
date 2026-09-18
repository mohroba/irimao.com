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
        $GLOBALS['test_admin_options'][Settings::OPTION_SHOW_COMPETITION_CARD_BUTTON] = '1';
        $GLOBALS['test_currency'] = '0';
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
        $GLOBALS['test_currency'] = '1';

        $this->assertTrue((new Settings())->is_add_to_cart_message_enabled());
    }

    public function test_competition_card_button_visibility_option_is_detected(): void
    {
        $settings = new Settings();
        $GLOBALS['test_currency'] = '1';
        $this->assertTrue($settings->is_competition_card_button_visible());

        $GLOBALS['test_admin_options'][Settings::OPTION_SHOW_COMPETITION_CARD_BUTTON] = '0';
        $GLOBALS['test_currency'] = '0';
        $this->assertFalse($settings->is_competition_card_button_visible());
    }

    public function test_shortcode_catalog_documents_custom_card_shortcodes(): void
    {
        $tags = array_column(Settings::shortcode_catalog(), 'tag');

        $this->assertContains('imao_card_user', $tags);
        $this->assertContains('imao_card_competition', $tags);
        $this->assertContains('imao_card_registration', $tags);
        $this->assertContains('imao_card_qr', $tags);
        $this->assertContains('crm_competition_bracket', $tags);
    }
}
