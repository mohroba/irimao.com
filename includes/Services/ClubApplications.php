<?php
namespace IMAOCustom\Services;

use IMAOCustom\Helpers\Bootstrap;

class ClubApplications {
    public function register(): void {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_crm_club_get', [ $this, 'ajax_get' ] );
        add_action( 'wp_ajax_crm_club_decide', [ $this, 'ajax_decide' ] );
    }

    public function register_cpt(): void {
        register_post_type( 'club_application', [
            'label'   => 'درخواست ثبت باشگاه',
            'public'  => false,
            'show_ui' => false,
            'supports'=> [ 'title' ],
        ] );
    }

    public function admin_menu(): void {
        add_users_page( 'باشگاه‌ها', 'باشگاه‌ها', 'manage_options', 'crm-clubs', [ $this, 'admin_page' ] );
    }

    public function enqueue_admin_assets( $hook ): void {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'crm-clubs' ) {
            $url = plugin_dir_url( dirname( __DIR__ ) );
            Bootstrap::enqueue();
            wp_enqueue_script( 'imao-club-admin', $url . 'assets/js/club-admin.js', [ 'jquery', 'bootstrap-js' ], '1.0.0', true );
            wp_localize_script( 'imao-club-admin', 'CLUB_ADMIN', [
                'ajax'        => admin_url( 'admin-ajax.php' ),
                'nonce_get'   => wp_create_nonce( 'crm_club_get' ),
                'nonce_decide'=> wp_create_nonce( 'crm_club_decide' ),
            ] );
        }
    }

    public function admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $q = new \WP_Query([
            'post_type'      => 'club_application',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => [ 'pending', 'publish', 'draft' ],
        ]);
        echo '<div class="wrap"><h1 class="wp-heading-inline">درخواست‌های ثبت باشگاه</h1><hr class="wp-header-end">';
        echo '<table id="club-table" class="wp-list-table widefat fixed striped"><thead><tr><th>ردیف</th><th>نام باشگاه</th><th>صاحب امتیاز</th><th>استان</th><th>شهر</th><th>وضعیت</th><th>اقدام</th></tr></thead><tbody>';
        $i = 1;
        while ( $q->have_posts() ) { $q->the_post();
            $pid      = get_the_ID();
            $uid      = (int) get_post_field( 'post_author', $pid );
            $status   = get_post_status( $pid );
            $province = get_post_meta( $pid, 'club_province', true );
            $city     = get_post_meta( $pid, 'club_city', true );
            $status_label = $status === 'publish' ? 'تأیید' : ( $status === 'draft' ? 'رد' : 'در انتظار' );
            echo '<tr data-id="'. esc_attr( $pid ) .'" data-user="'. esc_attr( $uid ) .'">';
            echo '<td>'. ( $i++ ) .'</td>';
            echo '<td>'. esc_html( get_the_title() ) .'</td>';
            echo '<td>'. esc_html( get_post_meta( $pid, 'owner_name', true ) ) .'</td>';
            echo '<td>'. esc_html( $province ) .'</td>';
            echo '<td>'. esc_html( $city ) .'</td>';
            echo '<td>'. esc_html( $status_label ) .'</td>';
            echo '<td><button class="btn btn-secondary view-club" data-pid="'. esc_attr( $pid ) .'">جزئیات</button> <button class="btn btn-success approve-club">تأیید</button> <button class="btn btn-danger reject-club">رد</button></td>';
            echo '</tr>';
        }
        \wp_reset_postdata();
        echo '</tbody></table></div>';
        echo '<div class="modal fade" id="club-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">جزئیات باشگاه</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"></div></div></div></div>';
    }

    public function ajax_get(): void {
        check_ajax_referer( 'crm_club_get', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $pid = intval( $_POST['post'] ?? 0 );
        if ( ! $pid ) {
            wp_send_json_error();
        }
        $data = [
            'title'    => get_the_title( $pid ),
            'owner'    => get_post_meta( $pid, 'owner_name', true ),
            'province' => get_post_meta( $pid, 'club_province', true ),
            'city'     => get_post_meta( $pid, 'club_city', true ),
            'postal'   => get_post_meta( $pid, 'club_postal', true ),
            'address'  => nl2br( esc_html( get_post_meta( $pid, 'club_address', true ) ) ),
            'lic'      => esc_url_raw( get_post_meta( $pid, 'license_image', true ) ),
            'reason'   => get_post_meta( $pid, 'rejection_reason', true ),
        ];
        wp_send_json_success( $data );
    }

    public function ajax_decide(): void {
        check_ajax_referer( 'crm_club_decide', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $pid    = intval( $_POST['post'] ?? 0 );
        $uid    = intval( $_POST['user'] ?? 0 );
        $dec    = sanitize_text_field( $_POST['decision'] ?? '' );
        $reason = sanitize_text_field( $_POST['reason'] ?? '' );
        if ( $dec === 'approve' ) {
            wp_update_post( [ 'ID' => $pid, 'post_status' => 'publish' ] );
            delete_post_meta( $pid, 'rejection_reason' );
            $club_name = get_the_title( $pid );
            $user      = get_userdata( $uid );
            if ( $user && $club_name ) {
                update_user_meta( $uid, 'club_name', $club_name );
                $user->add_role( 'club' );
            }
        } elseif ( $dec === 'reject' ) {
            wp_update_post( [ 'ID' => $pid, 'post_status' => 'draft' ] );
            update_post_meta( $pid, 'rejection_reason', $reason );
        } else {
            wp_send_json_error();
        }
        wp_send_json_success();
    }
}
