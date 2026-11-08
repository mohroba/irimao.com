<?php

namespace IMAOCustom\Migrations;

final class CreateClassEnrollments20261108060148 {
    public function up(): bool {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $wpdb->prefix . 'imao_class_enrollments';
        dbDelta( "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            class_id bigint unsigned NOT NULL,
            period varchar(7) NOT NULL,
            order_id bigint unsigned NOT NULL,
            billing longtext NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY student_class_period (user_id,class_id,period),
            KEY order_id (order_id)
        ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table;
    }
}
