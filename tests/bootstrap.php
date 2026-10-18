<?php
require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/Services/stubs.php';
require_once __DIR__ . '/Helpers/WalletDatabaseFake.php';
if ( ! isset( $GLOBALS['wpdb'] ) ) {
    $GLOBALS['wpdb'] = new WalletDatabaseFake();
}
