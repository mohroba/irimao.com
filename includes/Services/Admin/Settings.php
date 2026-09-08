<?php

namespace IMAOCustom\Services\Admin;

class Settings
{
    public const OPTION_ADD_TO_CART_MESSAGE = 'imao_enable_add_to_cart_message';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('init', [$this, 'configure_add_to_cart_message'], PHP_INT_MAX);
        add_action('wp_loaded', [$this, 'configure_add_to_cart_message'], PHP_INT_MAX);
    }

    public function add_menu(): void
    {
        add_options_page(
            'تنظیمات افزونه IMAO',
            'افزونه IMAO',
            'manage_options',
            'imao-plugin-settings',
            [$this, 'render_page']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            'imao_plugin_settings',
            self::OPTION_ADD_TO_CART_MESSAGE,
            [
                'type'              => 'boolean',
                'default'           => false,
                'sanitize_callback' => [$this, 'sanitize_checkbox'],
            ]
        );

        add_settings_section(
            'imao_woocommerce_messages',
            'پیام‌های ووکامرس',
            '__return_false',
            'imao-plugin-settings'
        );

        add_settings_field(
            self::OPTION_ADD_TO_CART_MESSAGE,
            'پیام افزودن به سبد خرید',
            [$this, 'render_add_to_cart_field'],
            'imao-plugin-settings',
            'imao_woocommerce_messages'
        );
    }

    public function sanitize_checkbox($value): string
    {
        return !empty($value) ? '1' : '0';
    }

    public function is_add_to_cart_message_enabled(): bool
    {
        return get_option(self::OPTION_ADD_TO_CART_MESSAGE, '0') === '1';
    }

    public function configure_add_to_cart_message(): void
    {
        if ($this->is_add_to_cart_message_enabled()) {
            remove_filter('wc_add_to_cart_message_html', '__return_null');
            remove_filter('wc_add_to_cart_message_html', '__return_null', 10);
            remove_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'], PHP_INT_MAX);
            return;
        }

        if (!has_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'])) {
            add_filter('wc_add_to_cart_message_html', [$this, 'disable_add_to_cart_message'], PHP_INT_MAX, 3);
        }
    }

    public function disable_add_to_cart_message($message, $products = [], $show_qty = false)
    {
        return null;
    }

    public function render_add_to_cart_field(): void
    {
        $enabled = $this->is_add_to_cart_message_enabled();
        echo '<label for="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '">';
        echo '<input type="checkbox" id="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '" name="' . esc_attr(self::OPTION_ADD_TO_CART_MESSAGE) . '" value="1" ' . checked($enabled, true, false) . '>';
        echo ' نمایش پیام استاندارد ووکامرس پس از افزودن محصول به سبد خرید';
        echo '</label>';
        echo '<p class="description">این گزینه به‌صورت پیش‌فرض غیرفعال است.</p>';
    }

    public function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap" dir="rtl">
            <h1>تنظیمات افزونه IMAO</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('imao_plugin_settings');
                do_settings_sections('imao-plugin-settings');
                submit_button();
                ?>
            </form>
            <?php do_action('imao_plugin_settings_after_sections'); ?>
        </div>
        <?php
    }
}
