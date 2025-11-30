<?php

namespace IMAOCustom\Helpers;

use WP_User;

class RepresentativeManager {
    private string $province_table;
    private string $city_table;
    private static bool $install_checked = false;

    public function __construct() {
        global $wpdb;
        $this->province_table = $wpdb->prefix . 'crm_province_reps';
        $this->city_table     = $wpdb->prefix . 'crm_city_reps';
    }

    public function get_province_table(): string {
        return $this->province_table;
    }

    public function get_city_table(): string {
        return $this->city_table;
    }

    public function install(): void {
        if ( self::$install_checked ) {
            return;
        }
        self::$install_checked = true;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql_province = "CREATE TABLE {$this->province_table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            province_code varchar(20) NOT NULL,
            assigned_by bigint unsigned NOT NULL,
            assigned_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            deactivated_at datetime NULL,
            deactivated_by bigint unsigned NULL,
            PRIMARY KEY  (id),
            KEY idx_province (province_code),
            KEY idx_user (user_id)
        ) {$charset};";

        $sql_city = "CREATE TABLE {$this->city_table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            province_code varchar(20) NOT NULL,
            city_name varchar(191) NOT NULL,
            assigned_by bigint unsigned NOT NULL,
            assigned_at datetime NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            deactivated_at datetime NULL,
            deactivated_by bigint unsigned NULL,
            PRIMARY KEY  (id),
            KEY idx_province_city (province_code, city_name),
            KEY idx_user (user_id)
        ) {$charset};";

        dbDelta( $sql_province );
        dbDelta( $sql_city );
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function assign_province_representative( int $user_id, string $province_code, int $assigned_by ): array {
        $province_code = sanitize_text_field( $province_code );
        if ( ! $user_id || ! $province_code ) {
            return [ 'ok' => false, 'message' => 'کاربر و استان الزامی هستند.' ];
        }

        $provinces = CityMap::get_provinces();
        if ( ! isset( $provinces[ $province_code ] ) ) {
            return [ 'ok' => false, 'message' => 'استان نامعتبر است.' ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return [ 'ok' => false, 'message' => 'کاربر یافت نشد.' ];
        }

        $this->install();
        global $wpdb;
        $now = current_time( 'mysql' );

        $previous_users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$this->province_table} WHERE province_code = %s AND status = 'active'",
                $province_code
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->province_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND status='active'",
                $now,
                $assigned_by,
                $province_code
            )
        );

        $wpdb->insert(
            $this->province_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'assigned_by'   => $assigned_by,
                'assigned_at'   => $now,
                'status'        => 'active',
            ],
            [ '%d', '%s', '%d', '%s', '%s' ]
        );

        $this->add_role_if_missing( $user, 'province_rep' );
        update_user_meta( $user_id, 'imao_province_code', $province_code );

        if ( $previous_users ) {
            foreach ( $previous_users as $previous_user ) {
                $this->maybe_remove_role( (int) $previous_user, 'province_rep', $this->province_table );
            }
        }

        return [ 'ok' => true ];
    }

    public function get_province_assignments(): array {
        $this->install();
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->province_table} ORDER BY assigned_at DESC" );
    }

    public function get_active_province_for_user( int $user_id ): ?array {
        $this->install();
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->province_table} WHERE user_id=%d AND status='active' ORDER BY assigned_at DESC LIMIT 1",
                $user_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function assign_city_representative( int $user_id, string $province_code, string $city_name, int $assigned_by ): array {
        $province_code = sanitize_text_field( $province_code );
        $city_name     = sanitize_text_field( $city_name );
        if ( ! $user_id || ! $province_code || ! $city_name ) {
            return [ 'ok' => false, 'message' => 'کاربر و شهر الزامی هستند.' ];
        }

        $provinces = CityMap::get_provinces();
        if ( ! isset( $provinces[ $province_code ] ) ) {
            return [ 'ok' => false, 'message' => 'استان نامعتبر است.' ];
        }

        $cities = CityMap::get_cities( $province_code );
        if ( ! in_array( $city_name, $cities, true ) ) {
            return [ 'ok' => false, 'message' => 'شهرستان انتخاب‌شده معتبر نیست.' ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return [ 'ok' => false, 'message' => 'کاربر یافت نشد.' ];
        }

        $this->install();
        global $wpdb;
        $now = current_time( 'mysql' );

        $previous_users = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$this->city_table} WHERE province_code=%s AND city_name=%s AND status='active'",
                $province_code,
                $city_name
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->city_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND city_name=%s AND status='active'",
                $now,
                $assigned_by,
                $province_code,
                $city_name
            )
        );

        $wpdb->insert(
            $this->city_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'city_name'     => $city_name,
                'assigned_by'   => $assigned_by,
                'assigned_at'   => $now,
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->add_role_if_missing( $user, 'city_rep' );

        if ( $previous_users ) {
            foreach ( $previous_users as $previous_user ) {
                $this->maybe_remove_role( (int) $previous_user, 'city_rep', $this->city_table );
            }
        }

        return [ 'ok' => true ];
    }

    /**
     * @return array<int,object>
     */
    public function get_city_assignments( ?string $province_code = null ): array {
        $this->install();
        global $wpdb;
        if ( $province_code ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->city_table} WHERE province_code=%s ORDER BY assigned_at DESC",
                    $province_code
                )
            );
        }
        return $wpdb->get_results( "SELECT * FROM {$this->city_table} ORDER BY assigned_at DESC" );
    }

    public function get_city_assignment( int $assignment_id ): ?array {
        $this->install();
        if ( ! $assignment_id ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->city_table} WHERE id=%d",
                $assignment_id
            ),
            ARRAY_A
        );
        return $row ?: null;
    }

    public function deactivate_city_assignment( int $assignment_id, int $user_id ): array {
        $this->install();
        if ( ! $assignment_id ) {
            return [ 'ok' => false, 'message' => 'شناسه یافت نشد.' ];
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->city_table} WHERE id=%d",
                $assignment_id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return [ 'ok' => false, 'message' => 'رکورد موجود نیست.' ];
        }
        $wpdb->update(
            $this->city_table,
            [
                'status'         => 'inactive',
                'deactivated_at' => current_time( 'mysql' ),
                'deactivated_by' => $user_id,
            ],
            [ 'id' => $assignment_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        $this->maybe_remove_role( (int) $row['user_id'], 'city_rep', $this->city_table );

        return [ 'ok' => true ];
    }

    private function add_role_if_missing( WP_User $user, string $role ): void {
        if ( ! in_array( $role, $user->roles, true ) ) {
            $user->add_role( $role );
        }
    }

    private function maybe_remove_role( int $user_id, string $role, string $table ): void {
        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return;
        }
        if ( ! in_array( $role, $user->roles, true ) ) {
            return;
        }
        global $wpdb;
        $active = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE user_id=%d AND status='active'",
                $user_id
            )
        );
        if ( $active === 0 ) {
            $user->remove_role( $role );
        }
    }
}
