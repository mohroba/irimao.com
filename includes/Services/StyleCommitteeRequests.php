<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\CityMap;

class StyleCommitteeRequests {
    public function register(): void {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_crm_stylecomm_change_status', [ $this, 'ajax_change_status' ] );
        add_action( 'wp_ajax_crm_stylecomm_delete', [ $this, 'ajax_delete' ] );
    }

    public function register_cpt(): void {
        register_post_type( 'style_committe_request', [
            'label'           => 'درخواست عضویت کمیته',
            'public'          => false,
            'show_ui'         => false,
            'supports'        => [ 'title' ],
            'capability_type' => 'style_committe_request',
            'map_meta_cap'    => true,
            'capabilities'    => [
                'read_post'             => 'read',
                'create_posts'          => 'read',
                'edit_post'             => 'read',
                'edit_posts'            => 'read',
                'edit_others_posts'     => 'read',
                'publish_posts'         => 'read',
                'read_private_posts'    => 'read',
                'delete_post'           => 'read',
                'delete_posts'          => 'read',
                'delete_others_posts'   => 'read',
                'delete_private_posts'  => 'read',
                'delete_published_posts'=> 'read',
                'edit_private_posts'    => 'read',
                'edit_published_posts'  => 'read',
            ],
        ] );
    }

    public function admin_menu(): void {
        add_users_page( 'کمیته‌های سبک', 'کمیته‌های سبک', 'manage_options', 'crm-style-committe', [ $this, 'admin_page' ] );
    }

    public function enqueue_admin_assets( $hook ): void {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'crm-style-committe' ) {
            $url = plugin_dir_url( dirname( __DIR__ ) );
            wp_enqueue_style( 'datatables', $url . 'assets/css/jquery.dataTables.min.css' );
            wp_enqueue_script( 'datatables', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], null, true );
            wp_enqueue_script( 'crm-stylecomm-admin', $url . 'assets/js/crm-stylecomm-admin.js', [ 'jquery','datatables' ], null, true );
            wp_localize_script( 'crm-stylecomm-admin', 'CRM_STYLECOMM', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'crm_stylecomm_nonce' ),
            ] );
        }
    }

    public function admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $q = new \WP_Query([
            'post_type'      => 'style_committe_request',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => [ 'pending', 'publish', 'draft' ],
        ]);
        echo '<div class="wrap"><h1>درخواست عضویت در کمیته‌های سبک</h1>';
        echo '<table id="crm-stylecomm-table" class="wp-list-table widefat striped">';
        echo '<thead><tr><th>ردیف</th><th>کاربر</th><th>استان</th><th>شهرستان</th><th>کمیته‌ها</th><th>وضعیت</th><th>دلیل رد</th><th>تاریخ ثبت</th><th>اقدامات</th></tr></thead><tbody>';
        $i = 1;
        while ( $q->have_posts() ) { $q->the_post();
            $pid     = get_the_ID();
            $author  = get_the_author_meta( 'display_name' );
            $status  = get_post_status();
            $status_label = $status === 'publish' ? 'تأیید شده' : ( $status === 'pending' ? 'در حال بررسی' : 'رد شده' );
            $comms   = (array) get_post_meta( $pid, 'committees', true );
            $reason  = get_post_meta( $pid, 'stylecomm_rejection_reason', true );
            $author_id = (int) get_post_field( 'post_author', $pid );
            $province = (string) get_user_meta( $author_id, 'residence_province', true );
            $province = CityMap::get_provinces()[ $province ] ?? $province;
            $city     = (string) get_user_meta( $author_id, 'residence_city', true );
            echo '<tr data-id="'. esc_attr( $pid ) .'">';
            echo '<td>'. ( $i++ ) .'</td>';
            echo '<td>'. esc_html( $author ) .'</td>';
            echo '<td>'. esc_html( $province ?: '—' ) .'</td>';
            echo '<td>'. esc_html( $city ?: '—' ) .'</td>';
            echo '<td>'. esc_html( implode( '، ', $comms ) ) .'</td>';
            echo '<td>'. esc_html( $status_label ) .'</td>';
            echo '<td>'. esc_html( $reason ?: '—' ) .'</td>';
            echo '<td>'. esc_html( get_the_date( 'Y/m/d H:i', $pid ) ) .'</td>';
            echo '<td><button class="button approve-btn" data-id="'. $pid .'">تأیید</button> <button class="button disapprove-btn" data-id="'. $pid .'">رد</button> <button class="button delete-btn" data-id="'. $pid .'">حذف</button></td>';
            echo '</tr>';
        }
        \wp_reset_postdata();
        echo '</tbody></table></div>';
    }

    public function ajax_change_status(): void {
        check_ajax_referer( 'crm_stylecomm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $post_id  = intval( $_POST['post_id'] ?? 0 );
        $decision = sanitize_text_field( $_POST['decision'] ?? '' );
        $reason   = sanitize_text_field( $_POST['reason'] ?? '' );
        if ( $decision === 'approve' ) {
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
            delete_post_meta( $post_id, 'stylecomm_rejection_reason' );
        } elseif ( $decision === 'disapprove' ) {
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            update_post_meta( $post_id, 'stylecomm_rejection_reason', $reason );
        } else {
            wp_send_json_error();
        }
        wp_send_json_success();
    }

    public function ajax_delete(): void {
        check_ajax_referer( 'crm_stylecomm_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( $post_id && get_post_type( $post_id ) === 'style_committe_request' ) {
            wp_delete_post( $post_id, true );
            wp_send_json_success();
        }
        wp_send_json_error();
    }
}

