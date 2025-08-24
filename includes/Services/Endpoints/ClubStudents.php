<?php
namespace IMAOCustom\Services\Endpoints;

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
        $uid    = get_current_user_id();
        $users  = get_users([
            'meta_key'   => 'club_id',
            'meta_value' => $uid,
            'fields'     => [ 'ID', 'display_name' ],
            'orderby'    => 'display_name',
            'order'      => 'ASC',
        ]);
        echo '<div class="club-form-container"><div class="sd-header" style="margin-bottom: 15px">شاگردان شما</div>';
        if ( empty( $users ) ) {
            echo '<p style="text-align:center;">کاربری یافت نشد.</p></div>';
            return;
        }
        echo '<table class="shop_table striped" style="text-align:center"><thead><tr><th>#</th><th>نام کاربر</th></tr></thead><tbody>';
        $i = 1;
        foreach ( $users as $u ) {
            echo '<tr><td>'. ( $i++ ) .'</td><td>'. esc_html( $u->display_name ) .'</td></tr>';
        }
        echo '</tbody></table></div>';
    }
}
