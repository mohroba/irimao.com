<?php

namespace IMAOCustom\Services\Admin;

use IMAOCustom\Helpers\FieldLabel;
use IMAOCustom\Helpers\Date;

class UserManagement {
    public function register(): void {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
        add_action( 'wp_ajax_crm_admin_update_role', [ $this, 'update_role' ] );
        add_action( 'wp_ajax_crm_admin_id_status', [ $this, 'change_status' ] );
        add_action( 'wp_ajax_crm_admin_toggle_ban', [ $this, 'toggle_ban' ] );
        add_action( 'admin_post_imao_login_as', [ $this, 'login_as_user' ] );
    }

    public function add_menu(): void {
        add_users_page( 'اطلاعات پایه', 'اطلاعات پایه', 'manage_options', 'imao-basic-info', [ $this, 'basic_info_page' ] );
        add_users_page( 'تأیید هویت حرفه‌ای', 'هویت حرفه‌ای', 'manage_options', 'imao-prof-identity', [ $this, 'prof_identity_page' ] );
    }

    public function enqueue( string $hook ): void {
        $page = $_GET['page'] ?? '';
        if ( ! in_array( $page, [ 'imao-basic-info', 'imao-prof-identity' ], true ) ) {
            return;
        }
        $base = plugin_dir_url( dirname( __DIR__, 2 ) ) . 'assets/';
        wp_enqueue_style( 'imao-datatables', $base . 'css/jquery.dataTables.min.css' );
        wp_enqueue_script( 'imao-datatables', $base . 'js/jquery.dataTables.min.js', [ 'jquery' ], null, true );
        wp_enqueue_style( 'imao-select2', $base . 'css/select2.min.css' );
        wp_enqueue_script( 'imao-select2', $base . 'js/select2.min.js', [ 'jquery' ], null, true );
        $admin_css = dirname( __DIR__, 2 ) . '/assets/css/crm-admin.css';
        $admin_js  = dirname( __DIR__, 2 ) . '/assets/js/crm-admin.js';
        wp_enqueue_style( 'imao-admin', $base . 'css/crm-admin.css', [], is_file( $admin_css ) ? (string) filemtime( $admin_css ) : null );
        wp_enqueue_script( 'imao-admin', $base . 'js/crm-admin.js', [ 'jquery', 'imao-datatables', 'imao-select2' ], is_file( $admin_js ) ? (string) filemtime( $admin_js ) : null, true );
        wp_localize_script( 'imao-admin', 'CRM_ADMIN', [
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'nonce' => wp_create_nonce( 'crm_admin_nonce' ),
        ] );
    }

    public static function basic_fields(): array {
        return [
            'billing_phone'      => 'شماره موبایل',
            'billing_email'      => 'ایمیل',
            'national_id'        => 'کد ملی',
            'first_name_fa'      => 'نام (فا)',
            'last_name_fa'       => 'نام‌خانوادگی (فا)',
            'first_name_en'      => 'نام (En)',
            'last_name_en'       => 'نام‌خانوادگی (En)',
            'gender'             => 'جنسیت',
            'father_name'        => 'نام پدر',
            'birth_date'         => 'تاریخ تولد',
            'marital_status'     => 'وضعیت تاهل',
            'education_status'   => 'وضعیت تحصیلی',
            'military_status'    => 'وضعیت خدمت',
            'birth_province'     => 'استان محل تولد',
            'birth_city'         => 'شهرستان محل تولد',
            'residence_province' => 'استان محل سکونت',
            'residence_city'     => 'شهرستان محل سکونت',
            'postal_code'        => 'کدپستی',
            'residence_address'  => 'آدرس',
            'club_id'            => 'باشگاه',
            'coach_id'           => 'مربی',
        ];
    }

    private function display_value( string $key, $value ): string {
        if ( in_array( $key, [ 'club_id', 'coach_id' ], true ) && $value ) {
            if ( $key === 'club_id' ) {
                $club_name = get_user_meta( (int) $value, 'club_name', true );
                if ( $club_name ) {
                    return (string) $club_name;
                }
            }
            $u = get_userdata( (int) $value );
            return $u ? $u->display_name : '';
        }
        return FieldLabel::get( $key, $value );
    }

    public function login_as_user(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
        $nonce   = $_GET['_wpnonce'] ?? '';
        if ( ! $user_id || ! wp_verify_nonce( $nonce, 'imao_login_as_' . $user_id ) ) {
            wp_die( 'Invalid request' );
        }
        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );
        $redirect = admin_url();
        wp_safe_redirect( $redirect );
        exit;
    }

    public function basic_info_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $filter_user_id = isset( $_GET['user_id'] ) ? (int) $_GET['user_id'] : 0;
        if ( isset( $_GET['edit_user'] ) ) {
            $this->basic_info_edit_form( (int) $_GET['edit_user'] );
            return;
        }
        $fields = self::basic_fields();
        $users  = $filter_user_id ? array_filter( [ get_userdata( $filter_user_id ) ] ) : get_users();
        echo '<div class="wrap table-responsive" style="max-width:90vw;overflow-x:auto;"><h1>اطلاعات پایه کاربران</h1>';
        echo '<table id="crm-basic-table" class="wp-list-table widefat striped">';
        echo '<thead><tr><th>ID</th><th>نام</th><th>وضعیت</th><th>تاریخ عضویت</th>';
        foreach ( $fields as $lbl ) {
            echo '<th>' . esc_html( $lbl ) . '</th>';
        }
        echo '<th>عملیات</th></tr></thead><tbody>';
        foreach ( $users as $u ) {
            echo '<tr>';
            $user_status = get_user_meta( $u->ID, 'imao_banned', true ) ? 'مسدود' : 'فعال';
            echo '<td>' . esc_html( $u->ID ) . '</td><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $user_status ) . '</td><td>' . esc_html( Date::to_jalali( (string) ( $u->user_registered ?? '' ) ) ?: '—' ) . '</td>';
            foreach ( $fields as $k => $lbl ) {
                $raw = get_user_meta( $u->ID, $k, true );
                if ( $k === 'billing_email' && ! $raw ) {
                    $raw = $u->user_email;
                }
                $val = $this->display_value( $k, $raw );
                echo '<td>' . esc_html( $val ) . '</td>';
            }
            $edit_link  = admin_url( 'users.php?page=imao-basic-info&edit_user=' . $u->ID );
            $login_link = wp_nonce_url( admin_url( 'admin-post.php?action=imao_login_as&user_id=' . $u->ID ), 'imao_login_as_' . $u->ID );
            echo '<td><a class="button" href="' . esc_url( $edit_link ) . '">ویرایش</a> <a class="button" href="' . esc_url( $login_link ) . '">ورود</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function basic_info_edit_form( int $user_id ): void {
        $fields = self::basic_fields();
        if ( isset( $_POST['imao_save_basic_admin'] ) && check_admin_referer( 'imao_basic_admin', 'imao_nonce' ) ) {
            $email = sanitize_email( $_POST['billing_email'] ?? '' );
            if ( $email ) {
                wp_update_user( [ 'ID' => $user_id, 'user_email' => $email ] );
                update_user_meta( $user_id, 'billing_email', $email );
            }
            foreach ( $fields as $meta_key => $label ) {
                if ( $meta_key === 'billing_email' ) {
                    continue;
                }
                if ( isset( $_POST[ $meta_key ] ) ) {
                    update_user_meta( $user_id, $meta_key, sanitize_text_field( $_POST[ $meta_key ] ) );
                }
            }
            echo '<div class="updated"><p>اطلاعات ذخیره شد.</p></div>';
        }
        echo '<div class="wrap"><h1>ویرایش اطلاعات کاربر #' . $user_id . '</h1>';
        echo '<form method="post">';
        wp_nonce_field( 'imao_basic_admin', 'imao_nonce' );
        echo '<table class="form-table striped">';
        $email_val = esc_attr( get_user_meta( $user_id, 'billing_email', true ) ?: get_userdata( $user_id )->user_email );
        echo '<tr><th>ایمیل</th><td><input type="email" name="billing_email" value="' . $email_val . '" class="regular-text"/></td></tr>';
        foreach ( $fields as $k => $lbl ) {
            if ( $k === 'billing_email' ) {
                continue;
            }
            $val = esc_attr( get_user_meta( $user_id, $k, true ) );
            echo '<tr><th>' . esc_html( $lbl ) . '</th><td><input type="text" name="' . esc_attr( $k ) . '" value="' . $val . '" class="regular-text"/></td></tr>';
        }
        echo '</table><p><input type="submit" name="imao_save_basic_admin" class="button-primary" value="ذخیره"></p>';
        echo '</form><p><a href="' . esc_url( admin_url( 'users.php?page=imao-basic-info' ) ) . '">← بازگشت به لیست</a></p></div>';
    }

    public function prof_identity_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $pro_fields = [
            'personal_photo'    => 'عکس پرسنلی',
            'birth_certificate' => 'تصویر شناسنامه',
            'national_id_card'  => 'تصویر کارت ملی',
        ];
        $users = get_users();
        echo '<div class="wrap"><h1>تأیید هویت حرفه‌ای</h1>';
        echo '<table id="crm-prof-table" class="wp-list-table widefat striped"><thead><tr>';
        echo '<th>ID</th><th>نام</th><th>استان</th><th>شهرستان</th><th>تاریخ عضویت</th>';
        foreach ( $pro_fields as $lbl ) {
            echo '<th>' . esc_html( $lbl ) . '</th>';
        }
        echo '<th>وضعیت</th><th>نقش</th><th>عملیات</th></tr></thead><tbody>';
        foreach ( $users as $u ) {
            $status = get_user_meta( $u->ID, 'identity_verified_professional', true ) ?: 'pending';
            $label  = $status === 'approved' ? 'مورد تایید' : ( $status === 'disapproved' ? 'مردود' : 'در انتظار' );
            echo '<tr>';
            $province = $this->display_value( 'residence_province', get_user_meta( $u->ID, 'residence_province', true ) );
            $city     = $this->display_value( 'residence_city', get_user_meta( $u->ID, 'residence_city', true ) );
            echo '<td>' . esc_html( $u->ID ) . '</td><td>' . esc_html( $u->display_name ) . '</td><td>' . esc_html( $province ?: '—' ) . '</td><td>' . esc_html( $city ?: '—' ) . '</td><td>' . esc_html( Date::to_jalali( (string) ( $u->user_registered ?? '' ) ) ?: '—' ) . '</td>';
            foreach ( $pro_fields as $key => $lbl ) {
                $uurl = get_user_meta( $u->ID, $key, true );
                $cell = $uurl ? '<a href="' . esc_url( $uurl ) . '" target="_blank">مشاهده</a>' : '-';
                echo '<td>' . $cell . '</td>';
            }
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td><select class="role-select" data-user-id="' . $u->ID . '" multiple data-placeholder="انتخاب نقش‌ها">';
            foreach ( wp_roles()->roles as $rk => $rd ) {
                $sel = in_array( $rk, $u->roles, true ) ? 'selected' : '';
                echo '<option value="' . esc_attr( $rk ) . '" ' . $sel . '>' . esc_html( $rd['name'] ) . '</option>';
            }
            echo '</select></td>';
            echo '<td><form method="post" class="identity-action-form" style="display:inline;">';
            echo '<input type="hidden" name="user_id" value="' . $u->ID . '">';
            echo wp_nonce_field( 'crm_admin_nonce', '_wpnonce', true, false );
            echo '<button class="button" name="crm_user_action" value="approve">تایید</button>';
            echo '<button class="button disapprove-btn" data-user="' . $u->ID . '">رد</button>';
            echo '<button class="button" name="crm_user_action" value="pending">در انتظار</button>';
            echo '</form>';
            $banned  = get_user_meta( $u->ID, 'imao_banned', true );
            $ban_lbl = $banned ? 'رفع مسدودی' : 'مسدود کردن';
            $ban_act = $banned ? 'unban' : 'ban';
            echo '<button class="button ban-user-btn" data-user="' . $u->ID . '" data-action="' . $ban_act . '">' . $ban_lbl . '</button>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    public function update_role(): void {
        check_ajax_referer( 'crm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $user = get_userdata( (int) ( $_POST['user'] ?? 0 ) );
        $roles = $this->sanitize_roles( $_POST['roles'] ?? ( $_POST['role'] ?? [] ) );
        if ( $user ) {
            $this->sync_user_roles( $user, $roles );
            wp_send_json_success( [ 'msg' => 'نقش‌ها بروزرسانی شدند' ] );
        }
        wp_send_json_error( [ 'msg' => 'خطا' ] );
    }

    public function change_status(): void {
        check_ajax_referer( 'crm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die();
        }
        $user_id = (int) ( $_POST['user'] ?? 0 );
        $act     = sanitize_text_field( $_POST['action_type'] ?? '' );
        $meta    = 'identity_verified_professional';
        if ( $act === 'approve' ) {
            update_user_meta( $user_id, $meta, 'approved' );
            delete_user_meta( $user_id, 'identity_rejection_reason_professional' );
            $photo = get_user_meta( $user_id, 'personal_photo', true );
            if ( $photo ) {
                update_user_meta( $user_id, 'simple_local_avatar', [ 'full' => esc_url_raw( $photo ) ] );
            }
        } elseif ( $act === 'pending' ) {
            update_user_meta( $user_id, $meta, 'pending' );
            delete_user_meta( $user_id, 'identity_rejection_reason_professional' );
        } elseif ( $act === 'disapprove' ) {
            update_user_meta( $user_id, $meta, 'disapproved' );
            update_user_meta( $user_id, 'identity_rejection_reason_professional', sanitize_text_field( $_POST['reason'] ?? '' ) );
        }
        if ( isset( $_POST['roles'] ) || isset( $_POST['role'] ) ) {
            $roles = $this->sanitize_roles( $_POST['roles'] ?? $_POST['role'] );
            $u = get_userdata( $user_id );
            if ( $u ) {
                $this->sync_user_roles( $u, $roles );
            }
        }
        wp_send_json_success();
    }

    /** @return string[] */
    private function sanitize_roles( $roles ): array {
        $roles   = is_array( $roles ) ? $roles : [ $roles ];
        $allowed = array_keys( wp_roles()->roles );
        $clean   = array_map( static function ( $role ): string {
            return sanitize_key( (string) $role );
        }, $roles );
        return array_values( array_unique( array_intersect( $clean, $allowed ) ) );
    }

    /** @param string[] $roles */
    public function sync_user_roles( $user, array $roles ): void {
        $current = is_array( $user->roles ?? null ) ? $user->roles : [];
        foreach ( array_diff( $current, $roles ) as $role ) {
            $user->remove_role( $role );
        }
        foreach ( array_diff( $roles, $current ) as $role ) {
            $user->add_role( $role );
        }
    }

    public function toggle_ban(): void {
        check_ajax_referer( 'crm_admin_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $user_id = (int) ( $_POST['user'] ?? 0 );
        $act     = sanitize_text_field( $_POST['ban_action'] ?? '' );
        if ( ! $user_id || ! in_array( $act, [ 'ban', 'unban' ], true ) ) {
            wp_send_json_error();
        }
        if ( $act === 'ban' ) {
            update_user_meta( $user_id, 'imao_banned', 1 );
        } else {
            delete_user_meta( $user_id, 'imao_banned' );
        }
        wp_send_json_success();
    }
}
