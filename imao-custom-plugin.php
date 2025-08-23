<?php
/**
 * Plugin Name: پلاگین اختصاصی ایمائو
 * Description: افزونه‌ای برای ورود، ثبت‌نام و داشبورد اختصاصی.
 * Version: 1.0.0
 * Author: IRIMAO
 * Text Domain: imao-custom-plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

define( 'IMAO_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/autoload.php';

IMAOCustom\Plugin::get_instance();
