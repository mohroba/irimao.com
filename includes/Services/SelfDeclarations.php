<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\SelfDeclarationData;

class SelfDeclarations {
    public function register(): void {
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_crm_selfdec_change_status', [ $this, 'ajax_change_status' ] );
    }

    public function register_cpt(): void {
        register_post_type( 'self_declaration', [
            'label'   => 'خوداظهاری‌ها',
            'public'  => false,
            'show_ui' => false,
            'supports'=> [ 'title' ],
        ] );
    }

    public function admin_menu(): void {
        add_users_page( 'خوداظهاری', 'خوداظهاری', 'manage_options', 'crm-self-declarations', [ $this, 'admin_page' ] );
    }

    public function enqueue_admin_assets( $hook ): void {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'crm-self-declarations' ) {
            $url = plugin_dir_url( dirname( __DIR__ ) );
            wp_enqueue_style( 'datatables', $url . 'assets/css/jquery.dataTables.min.css' );
            wp_enqueue_script( 'datatables', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], null, true );
            wp_enqueue_style( 'select2', $url . 'assets/css/select2.min.css' );
            wp_enqueue_script( 'select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], null, true );
            wp_enqueue_script( 'crm-selfdec-admin', $url . 'assets/js/crm-selfdec-admin.js', [ 'jquery','datatables','select2' ], null, true );
            wp_localize_script( 'crm-selfdec-admin', 'CRM_SELFDEC', [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( 'crm_selfdec_nonce' ),
            ] );
        }
    }

    public function admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $coursetypes = SelfDeclarationData::course_types();
        $wc_states   = class_exists( 'WC_Countries' ) ? ( new \WC_Countries() )->get_states( 'IR' ) : [];
        $q = new \WP_Query([
            'post_type'      => 'self_declaration',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'post_status'    => [ 'pending', 'publish', 'draft' ],
        ]);
        echo '<div class="wrap"><h1>مدیریت خوداظهاری‌ها</h1>';
        echo '<table id="crm-selfdec-table" class="wp-list-table widefat striped">';
        echo '<thead><tr><th>ردیف</th><th>کاربر</th><th>نوع حکم</th><th>درجه</th><th>شماره حکم</th><th>تاریخ اخذ</th><th>تاریخ آزمون</th><th>تاریخ تئوری</th><th>هیئت</th><th>فایل</th><th>وضعیت</th><th>اقدامات</th></tr></thead><tbody>';
        $i = 1;
        while ( $q->have_posts() ) { $q->the_post();
            $pid     = get_the_ID();
            $author  = get_the_author_meta( 'display_name' );
            $status  = get_post_status();
            $status_label = $status === 'publish' ? 'تأیید شده' : ( $status === 'pending' ? 'در حال بررسی' : 'رد شده' );
            $type_id      = (int) get_post_meta( $pid, 'coursetype', true );
            $degree_raw   = (string) get_post_meta( $pid, 'degree', true );
            $degree_label = SelfDeclarationData::degree_label( $type_id, $degree_raw );
            $hokm_number  = get_post_meta( $pid, 'hokm_number', true );
            $getdate      = get_post_meta( $pid, 'getdate', true );
            $exam_date    = get_post_meta( $pid, 'exam_date', true );
            $theory_date  = get_post_meta( $pid, 'theory_date', true );
            $board_code   = (string) get_post_meta( $pid, 'boards', true );
            $board_label  = $wc_states[ $board_code ] ?? $board_code ?: '—';
            $image_url    = esc_url( get_post_meta( $pid, 'image_url', true ) );
            echo '<tr data-id="'. esc_attr( $pid ) .'">';
            echo '<td>'. ($i++) .'</td>';
            echo '<td>'. esc_html( $author ) .'</td>';
            echo '<td>'. esc_html( $coursetypes[ $type_id ] ?? '—' ) .'</td>';
            echo '<td>'. esc_html( $degree_label ) .'</td>';
            echo '<td>'. esc_html( $hokm_number ?: '—' ) .'</td>';
            echo '<td>'. esc_html( $getdate ?: '—' ) .'</td>';
            echo '<td>'. esc_html( $exam_date ?: '—' ) .'</td>';
            echo '<td>'. esc_html( $theory_date ?: '—' ) .'</td>';
            echo '<td>'. esc_html( $board_label ) .'</td>';
            echo '<td>'. ( $image_url ? '<a href="'.$image_url.'" target="_blank">مشاهده</a>' : '—' ) .'</td>';
            echo '<td>'. $status_label .'</td>';
            echo '<td><button class="button approve-btn" data-id="'.$pid.'">تأیید</button> <button class="button disapprove-btn" data-id="'.$pid.'">رد</button></td>';
            echo '</tr>';
        }
        \wp_reset_postdata();
        echo '</tbody></table></div>';
    }

    public function ajax_change_status(): void {
        check_ajax_referer( 'crm_selfdec_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }
        $post_id  = intval( $_POST['post_id'] ?? 0 );
        $decision = sanitize_text_field( $_POST['decision'] ?? '' );
        $reason   = sanitize_text_field( $_POST['reason'] ?? '' );
        if ( $decision === 'approve' ) {
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'publish' ] );
            delete_post_meta( $post_id, 'selfdec_rejection_reason' );
        } elseif ( $decision === 'disapprove' ) {
            wp_update_post( [ 'ID' => $post_id, 'post_status' => 'draft' ] );
            update_post_meta( $post_id, 'selfdec_rejection_reason', $reason );
        } else {
            wp_send_json_error();
        }
        wp_send_json_success();
    }
}

