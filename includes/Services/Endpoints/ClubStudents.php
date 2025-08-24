<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\UserMeta;

class ClubStudents {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_action( 'woocommerce_account_club-students_endpoint', [ $this, 'content' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'club-students', EP_ROOT | EP_PAGES );
    }

    public function content(): void {
        if ( ! is_user_logged_in() ) {
            echo '<p style="text-align:center;color:#c00;">لطفاً ابتدا وارد شوید.</p>';
            return;
        }

        $uid   = get_current_user_id();
        $users = get_users( [
            'meta_key'   => 'club_id',
            'meta_value' => $uid,
            'fields'     => [ 'ID', 'display_name', 'user_email' ],
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ] );

        echo '<div class="club-form-container"><div class="sd-header" style="margin-bottom: 15px">شاگردان شما</div>';
        if ( empty( $users ) ) {
            echo '<p style="text-align:center;">کاربری یافت نشد.</p></div>';
            return;
        }

        $url = plugin_dir_url( dirname( __DIR__, 2 ) );
        wp_enqueue_style( 'imao-dt', $url . 'assets/css/jquery.dataTables.min.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-dt', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], '1.0.0', true );
        wp_add_inline_script( 'imao-dt', 'jQuery(function($){$("#club-students-table").DataTable({language:{url:"https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json"},pageLength:20});});' );

        $columns = [
            'user_email'         => 'ایمیل',
            'billing_phone'      => 'موبایل',
            'national_id'        => 'کد ملی',
            'gender'             => 'جنسیت',
            'first_name_fa'      => 'نام (فارسی)',
            'last_name_fa'       => 'نام خانوادگی (فارسی)',
            'first_name_en'      => 'نام (En)',
            'last_name_en'       => 'نام خانوادگی (En)',
            'father_name'        => 'نام پدر',
            'birth_date'         => 'تاریخ تولد',
            'birth_province'     => 'استان تولد',
            'birth_city'         => 'شهر تولد',
            'marital_status'     => 'وضعیت تأهل',
            'education_status'   => 'وضعیت تحصیلی',
            'military_status'    => 'وضعیت خدمت',
            'residence_province' => 'استان سکونت',
            'residence_city'     => 'شهر سکونت',
            'postal_code'        => 'کد پستی',
            'residence_address'  => 'آدرس',
            'iban'               => 'شبا',
            'card_number'        => 'کارت',
            'coach_id'           => 'شناسه مربی',
            'club_id'            => 'شناسه باشگاه',
        ];

        echo '<table id="club-students-table" class="shop_table striped" style="text-align:center"><thead><tr><th>#</th><th>نام کاربر</th>';
        foreach ( $columns as $label ) {
            echo '<th>' . esc_html( $label ) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $i = 1;
        foreach ( $users as $u ) {
            $meta = UserMeta::get_many( $u->ID, array_keys( $columns ) );
            echo '<tr><td>' . ( $i++ ) . '</td><td>' . esc_html( $u->display_name ) . '</td>';
            foreach ( $columns as $key => $label ) {
                $val = $key === 'user_email' ? $u->user_email : ( $meta[ $key ] ?? '' );
                echo '<td>' . esc_html( (string) $val ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
}
