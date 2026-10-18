<?php

namespace IMAOCustom\Migrations;

/** Additive schema migration for the monthly tuition ledger. */
final class CreateMonthlyTuition202610180001 {
    public function up(): bool {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'imao_class_tuition';
        dbDelta( "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint unsigned NOT NULL,
            order_item_id bigint unsigned NOT NULL,
            class_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            paid_at datetime NOT NULL,
            due_date date NOT NULL,
            amount decimal(18,2) NOT NULL,
            snapshot longtext NOT NULL,
            status varchar(20) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY order_item_id (order_item_id),
            KEY student_class (user_id,class_id,status),
            KEY order_id (order_id),
            KEY paid_at (paid_at)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }
}
