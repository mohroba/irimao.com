<?php

namespace IMAOCustom\Helpers;

use WP_User;

class RepresentativeManager {
    private string $province_table;
    private string $city_table;
    private static bool $install_checked = false;
    public const ROLE_PROVINCE       = 'province_rep';
    public const ROLE_CITY           = 'city_rep';
    public const GENDER_MALE          = 'male';
    public const GENDER_FEMALE        = 'female';
    private const ALLOWED_GENDERS     = [ self::GENDER_MALE, self::GENDER_FEMALE ];

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
            gender varchar(20) NOT NULL DEFAULT '',
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
            gender varchar(20) NOT NULL DEFAULT '',
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

        $this->ensure_gender_columns();
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function assign_province_representative( int $user_id, string $province_code, int $assigned_by, string $gender ): array {
        $province_code = sanitize_text_field( $province_code );
        $gender        = $this->normalize_gender( $gender );
        $this->ensure_roles_exist();
        if ( ! $user_id || ! $province_code ) {
            return [ 'ok' => false, 'message' => 'کاربر و استان الزامی هستند.' ];
        }
        if ( ! $gender ) {
            return [ 'ok' => false, 'message' => 'انتخاب جنسیت الزامی است.' ];
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
                "SELECT user_id FROM {$this->province_table} WHERE province_code = %s AND status = 'active' AND (gender=%s OR gender='' OR gender IS NULL)",
                $province_code,
                $gender
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->province_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND status='active' AND (gender=%s OR gender='' OR gender IS NULL)",
                $now,
                $assigned_by,
                $province_code,
                $gender
            )
        );

        $wpdb->insert(
            $this->province_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'gender'        => $gender,
                'assigned_by'   => $assigned_by,
                'assigned_at'   => $now,
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->add_role_if_missing( $user, self::ROLE_PROVINCE );
        update_user_meta( $user_id, 'imao_province_code', $province_code );

        if ( $previous_users ) {
            foreach ( $previous_users as $previous_user ) {
                $this->maybe_remove_role( (int) $previous_user, self::ROLE_PROVINCE, $this->province_table );
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

    public function assign_city_representative( int $user_id, string $province_code, string $city_name, int $assigned_by, string $gender ): array {
        $province_code = sanitize_text_field( $province_code );
        $city_name     = sanitize_text_field( $city_name );
        $gender        = $this->normalize_gender( $gender );
        $this->ensure_roles_exist();
        if ( ! $user_id || ! $province_code || ! $city_name ) {
            return [ 'ok' => false, 'message' => 'کاربر و شهر الزامی هستند.' ];
        }
        if ( ! $gender ) {
            return [ 'ok' => false, 'message' => 'انتخاب جنسیت الزامی است.' ];
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
                "SELECT user_id FROM {$this->city_table} WHERE province_code=%s AND city_name=%s AND status='active' AND (gender=%s OR gender='' OR gender IS NULL)",
                $province_code,
                $city_name,
                $gender
            )
        );

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->city_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND city_name=%s AND status='active' AND (gender=%s OR gender='' OR gender IS NULL)",
                $now,
                $assigned_by,
                $province_code,
                $city_name,
                $gender
            )
        );

        $wpdb->insert(
            $this->city_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'city_name'     => $city_name,
                'gender'        => $gender,
                'assigned_by'   => $assigned_by,
                'assigned_at'   => $now,
                'status'        => 'active',
            ],
            [ '%d', '%s', '%s', '%s', '%d', '%s', '%s' ]
        );

        $this->add_role_if_missing( $user, self::ROLE_CITY );

        if ( $previous_users ) {
            foreach ( $previous_users as $previous_user ) {
                $this->maybe_remove_role( (int) $previous_user, self::ROLE_CITY, $this->city_table );
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

    public function get_province_assignment( int $assignment_id ): ?array {
        $this->install();
        if ( ! $assignment_id ) {
            return null;
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->province_table} WHERE id=%d",
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

        $this->maybe_remove_role( (int) $row['user_id'], self::ROLE_CITY, $this->city_table );

        return [ 'ok' => true ];
    }

    public function deactivate_province_assignment( int $assignment_id, int $user_id ): array {
        $this->install();
        if ( ! $assignment_id ) {
            return [ 'ok' => false, 'message' => 'شناسه یافت نشد.' ];
        }
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->province_table} WHERE id=%d",
                $assignment_id
            ),
            ARRAY_A
        );
        if ( ! $row ) {
            return [ 'ok' => false, 'message' => 'رکورد موجود نیست.' ];
        }
        $wpdb->update(
            $this->province_table,
            [
                'status'         => 'inactive',
                'deactivated_at' => current_time( 'mysql' ),
                'deactivated_by' => $user_id,
            ],
            [ 'id' => $assignment_id ],
            [ '%s', '%s', '%d' ],
            [ '%d' ]
        );

        $this->maybe_remove_role( (int) $row['user_id'], self::ROLE_PROVINCE, $this->province_table );

        return [ 'ok' => true ];
    }

    public function delete_city_assignment( int $assignment_id ): array {
        $this->install();
        if ( ! $assignment_id ) {
            return [ 'ok' => false, 'message' => 'شناسه یافت نشد.' ];
        }
        global $wpdb;
        $row = $this->get_city_assignment( $assignment_id );
        if ( ! $row ) {
            return [ 'ok' => false, 'message' => 'رکورد موجود نیست.' ];
        }
        $wpdb->delete( $this->city_table, [ 'id' => $assignment_id ], [ '%d' ] );
        $this->maybe_remove_role( (int) $row['user_id'], self::ROLE_CITY, $this->city_table );
        return [ 'ok' => true ];
    }

    public function delete_province_assignment( int $assignment_id ): array {
        $this->install();
        if ( ! $assignment_id ) {
            return [ 'ok' => false, 'message' => 'شناسه یافت نشد.' ];
        }
        global $wpdb;
        $row = $this->get_province_assignment( $assignment_id );
        if ( ! $row ) {
            return [ 'ok' => false, 'message' => 'رکورد موجود نیست.' ];
        }
        $wpdb->delete( $this->province_table, [ 'id' => $assignment_id ], [ '%d' ] );
        $this->maybe_remove_role( (int) $row['user_id'], self::ROLE_PROVINCE, $this->province_table );
        return [ 'ok' => true ];
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function update_province_assignment( int $id, int $user_id, string $province_code, string $gender, string $status, int $updated_by ): array {
        $this->install();
        $province_code = sanitize_text_field( $province_code );
        $gender        = $this->normalize_gender( $gender );
        $status        = $status === 'inactive' ? 'inactive' : 'active';
        $this->ensure_roles_exist();

        if ( ! $id || ! $user_id || ! $province_code || ! $gender ) {
            return [ 'ok' => false, 'message' => 'همه فیلدها الزامی هستند.' ];
        }

        $current = $this->get_province_assignment( $id );
        if ( ! $current ) {
            return [ 'ok' => false, 'message' => 'رکورد یافت نشد.' ];
        }

        $provinces = CityMap::get_provinces();
        if ( ! isset( $provinces[ $province_code ] ) ) {
            return [ 'ok' => false, 'message' => 'استان نامعتبر است.' ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return [ 'ok' => false, 'message' => 'کاربر یافت نشد.' ];
        }

        global $wpdb;
        $now = current_time( 'mysql' );

        if ( $status === 'active' ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->province_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND id<>%d AND status='active' AND (gender=%s OR gender='' OR gender IS NULL)",
                    $now,
                    $updated_by,
                    $province_code,
                    $id,
                    $gender
                )
            );
        }

        $wpdb->update(
            $this->province_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'gender'        => $gender,
                'status'        => $status,
                'assigned_at'   => $status === 'active' ? $now : $current['assigned_at'],
                'assigned_by'   => $status === 'active' ? $updated_by : $current['assigned_by'],
                'deactivated_at'=> $status === 'inactive' ? $now : null,
                'deactivated_by'=> $status === 'inactive' ? $updated_by : null,
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ],
            [ '%d' ]
        );

        if ( $status === 'active' ) {
            $this->add_role_if_missing( $user, self::ROLE_PROVINCE );
            update_user_meta( $user_id, 'imao_province_code', $province_code );
        } else {
            $this->maybe_remove_role( $user_id, self::ROLE_PROVINCE, $this->province_table );
        }

        if ( $current['user_id'] !== $user_id ) {
            $this->maybe_remove_role( (int) $current['user_id'], self::ROLE_PROVINCE, $this->province_table );
        }

        return [ 'ok' => true ];
    }

    /**
     * @return array{ok:bool,message?:string}
     */
    public function update_city_assignment( int $id, int $user_id, string $province_code, string $city_name, string $gender, string $status, int $updated_by ): array {
        $this->install();
        $province_code = sanitize_text_field( $province_code );
        $city_name     = sanitize_text_field( $city_name );
        $gender        = $this->normalize_gender( $gender );
        $status        = $status === 'inactive' ? 'inactive' : 'active';
        $this->ensure_roles_exist();

        if ( ! $id || ! $user_id || ! $province_code || ! $city_name || ! $gender ) {
            return [ 'ok' => false, 'message' => 'همه فیلدها الزامی هستند.' ];
        }

        $current = $this->get_city_assignment( $id );
        if ( ! $current ) {
            return [ 'ok' => false, 'message' => 'رکورد یافت نشد.' ];
        }

        $provinces = CityMap::get_provinces();
        if ( ! isset( $provinces[ $province_code ] ) ) {
            return [ 'ok' => false, 'message' => 'استان نامعتبر است.' ];
        }

        $cities = CityMap::get_cities( $province_code );
        if ( ! in_array( $city_name, $cities, true ) ) {
            return [ 'ok' => false, 'message' => 'شهرستان نامعتبر است.' ];
        }

        $user = get_userdata( $user_id );
        if ( ! $user instanceof WP_User ) {
            return [ 'ok' => false, 'message' => 'کاربر یافت نشد.' ];
        }

        global $wpdb;
        $now = current_time( 'mysql' );

        if ( $status === 'active' ) {
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE {$this->city_table} SET status='inactive', deactivated_at=%s, deactivated_by=%d WHERE province_code=%s AND city_name=%s AND id<>%d AND status='active' AND (gender=%s OR gender='' OR gender IS NULL)",
                    $now,
                    $updated_by,
                    $province_code,
                    $city_name,
                    $id,
                    $gender
                )
            );
        }

        $wpdb->update(
            $this->city_table,
            [
                'user_id'       => $user_id,
                'province_code' => $province_code,
                'city_name'     => $city_name,
                'gender'        => $gender,
                'status'        => $status,
                'assigned_at'   => $status === 'active' ? $now : $current['assigned_at'],
                'assigned_by'   => $status === 'active' ? $updated_by : $current['assigned_by'],
                'deactivated_at'=> $status === 'inactive' ? $now : null,
                'deactivated_by'=> $status === 'inactive' ? $updated_by : null,
            ],
            [ 'id' => $id ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%d' ],
            [ '%d' ]
        );

        if ( $status === 'active' ) {
            $this->add_role_if_missing( $user, self::ROLE_CITY );
        } else {
            $this->maybe_remove_role( $user_id, self::ROLE_CITY, $this->city_table );
        }

        if ( $current['user_id'] !== $user_id ) {
            $this->maybe_remove_role( (int) $current['user_id'], self::ROLE_CITY, $this->city_table );
        }

        return [ 'ok' => true ];
    }

    public static function gender_labels(): array {
        return [
            self::GENDER_MALE   => 'نماینده آقایان',
            self::GENDER_FEMALE => 'نماینده بانوان',
        ];
    }

    public static function format_gender( string $gender ): string {
        $labels = self::gender_labels();
        return $labels[ $gender ] ?? $gender;
    }

    public function find_user_by_national_id( string $national_id ): ?WP_User {
        $national_id = sanitize_text_field( $national_id );
        if ( ! $national_id ) {
            return null;
        }
        $users = get_users(
            [
                'meta_key'   => 'national_id',
                'meta_value' => $national_id,
                'number'     => 1,
                'count_total'=> false,
            ]
        );
        return $users ? $users[0] : null;
    }

    private function normalize_gender( string $gender ): string {
        $gender = strtolower( sanitize_text_field( $gender ) );
        return in_array( $gender, self::ALLOWED_GENDERS, true ) ? $gender : '';
    }

    private function ensure_gender_columns(): void {
        global $wpdb;
        $province_has_gender = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->province_table} LIKE %s", 'gender' ) );
        if ( ! $province_has_gender ) {
            $wpdb->query( "ALTER TABLE {$this->province_table} ADD gender varchar(20) NOT NULL DEFAULT '' AFTER province_code" );
        }

        $city_has_gender = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$this->city_table} LIKE %s", 'gender' ) );
        if ( ! $city_has_gender ) {
            $wpdb->query( "ALTER TABLE {$this->city_table} ADD gender varchar(20) NOT NULL DEFAULT '' AFTER city_name" );
        }
    }

    private function ensure_roles_exist(): void {
        if ( ! get_role( self::ROLE_PROVINCE ) ) {
            add_role( self::ROLE_PROVINCE, 'نماینده استان', [ 'read' => true, 'assign_city_representatives' => true ] );
        }
        if ( ! get_role( self::ROLE_CITY ) ) {
            add_role( self::ROLE_CITY, 'نماینده شهرستان', [ 'read' => true ] );
        }
    }

    private function add_role_if_missing( WP_User $user, string $role ): void {
        $this->ensure_roles_exist();
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
