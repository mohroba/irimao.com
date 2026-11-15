<?php

namespace IMAOCustom\Migrations;

final class CreateWalletCheckoutLedger20261115090000 {
    public function up(): bool {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'imao_wallet_checkout';
        dbDelta( "CREATE TABLE {$table} (
            order_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            amount decimal(20,2) NOT NULL,
            balance_before decimal(20,2) NOT NULL,
            balance_after decimal(20,2) NOT NULL,
            status varchar(16) NOT NULL,
            origin varchar(24) NOT NULL DEFAULT 'checkout',
            created_at datetime NOT NULL,
            refunded_at datetime DEFAULT NULL,
            PRIMARY KEY (order_id),
            KEY user_status (user_id,status)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }
}
