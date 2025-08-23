<?php
namespace IMAOCustom\Services;

class Ranking {
    public function register(): void {
        register_activation_hook( IMAO_PLUGIN_FILE, [ $this, 'activate' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_shortcode( 'crm_competition_rankings', [ $this, 'competition_rankings_shortcode' ] );
        add_shortcode( 'crm_my_rankings', [ $this, 'my_rankings_shortcode' ] );
        add_action( 'init', [ $this, 'register_endpoint' ] );
        add_action( 'woocommerce_account_my-rankings_endpoint', fn() => print do_shortcode( '[crm_my_rankings]' ) );
    }

    public function activate(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql_points = "CREATE TABLE {$wpdb->prefix}crm_points (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                competition_id bigint unsigned NOT NULL,
                weight_class bigint unsigned NOT NULL,
                points int NOT NULL DEFAULT 0,
                assigned_date datetime NOT NULL,
                PRIMARY KEY (id),
                KEY idx_comp (competition_id),
                KEY idx_user (user_id)
        ) $charset;";
        dbDelta( $sql_points );
        $sql_settings = "CREATE TABLE {$wpdb->prefix}crm_settings (
                opt_key varchar(60) NOT NULL PRIMARY KEY,
                opt_val varchar(191) NOT NULL
        ) $charset;";
        dbDelta( $sql_settings );
        $wpdb->replace( $wpdb->prefix . 'crm_settings', [ 'opt_key' => 'points_expiry_days', 'opt_val' => '365' ], [ '%s', '%s' ] );
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint( 'my-rankings', EP_ROOT | EP_PAGES );
    }

    public function add_admin_menu(): void {
        add_users_page( 'امتیازات / رده‌بندی', 'امتیازات / رده‌بندی', 'manage_options', 'crm-points-manager', [ $this, 'render_admin_page' ] );
    }

    public function render_admin_page(): void {
        $tab = $_GET['tab'] ?? 'assign';
        echo '<div class="wrap"><h1 class="wp-heading-inline">مدیریت امتیازات</h1><hr>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab ' . ( $tab === 'assign' ? 'nav-tab-active' : '' ) . '" href="?page=crm-points-manager&tab=assign">اختصاص امتیاز</a>';
        echo '<a class="nav-tab ' . ( $tab === 'rank' ? 'nav-tab-active' : '' ) . '" href="?page=crm-points-manager&tab=rank">مشاهده رده‌بندی</a>';
        echo '<a class="nav-tab ' . ( $tab === 'settings' ? 'nav-tab-active' : '' ) . '" href="?page=crm-points-manager&tab=settings">تنظیمات</a>';
        echo '</h2>';
        if ( $tab === 'settings' ) {
            $this->render_settings_tab();
        } elseif ( $tab === 'rank' ) {
            echo $this->competition_rankings_shortcode();
        } else {
            $this->render_assign_tab();
        }
        echo '</div>';
    }

    private function render_settings_tab(): void {
        global $wpdb;
        if ( isset( $_POST['save_settings'] ) ) {
            check_admin_referer( 'crm_points_settings' );
            $days = max( 0, intval( $_POST['expiry_days'] ) );
            $wpdb->replace( $wpdb->prefix . 'crm_settings', [ 'opt_key' => 'points_expiry_days', 'opt_val' => (string) $days ], [ '%s', '%s' ] );
            echo '<div class="updated"><p>ذخیره شد.</p></div>';
        }
        $expiry = intval( $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" ) );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'crm_points_settings' ); ?>
            <table class="form-table">
                <tr>
                    <th scope="row">انقضای امتیازها (روز)</th>
                    <td><input type="number" name="expiry_days" value="<?php echo $expiry; ?>" min="0"><p class="description">0 = بدون انقضا</p></td>
                </tr>
            </table>
            <?php submit_button( 'ذخیره', 'primary', 'save_settings' ); ?>
        </form>
        <?php
    }

    private function render_assign_tab(): void {
        global $wpdb;
        if ( isset( $_POST['crm_assign_points'] ) ) {
            check_admin_referer( 'crm_assign_points' );
            $user_id        = intval( $_POST['user_id'] ?? 0 );
            $competition_id = intval( $_POST['competition_id'] ?? 0 );
            $weight_class   = intval( $_POST['weight_class'] ?? 0 );
            $points         = intval( $_POST['points'] ?? 0 );
            if ( ! $competition_id || ! $user_id || ! $weight_class ) {
                echo '<div class="error"><p>تمام فیلدها الزامی است.</p></div>';
            } else {
                $wpdb->insert( $wpdb->prefix . 'crm_points', [
                    'user_id'        => $user_id,
                    'competition_id' => $competition_id,
                    'weight_class'   => $weight_class,
                    'points'         => $points,
                    'assigned_date'  => current_time( 'mysql' ),
                ], [ '%d', '%d', '%d', '%d', '%s' ] );
                echo '<div class="updated"><p>امتیاز ثبت شد.</p></div>';
            }
        }
        ?>
        <form method="post">
            <?php wp_nonce_field( 'crm_assign_points' ); ?>
            <table class="form-table">
                <tr>
                    <th>مسابقه<span style="color:#d00">*</span></th>
                    <td>
                        <select name="competition_id" class="crm-select2" required>
                            <option value="">— انتخاب کنید —</option>
                            <?php
                            foreach ( get_posts( [
                                'post_type' => 'competition',
                                'posts_per_page' => -1,
                                'post_status' => 'publish',
                                'orderby' => 'date',
                                'order' => 'DESC',
                            ] ) as $c ) {
                                printf( '<option value="%d">%s</option>', $c->ID, esc_html( $c->post_title ) );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>کلاس وزنی<span style="color:#d00">*</span></th>
                    <td>
                        <?php
                        echo str_replace( 'class=\'postform\'', 'class="postform crm-select2"', wp_dropdown_categories( [
                            'taxonomy' => 'weight_class',
                            'name' => 'weight_class',
                            'show_option_none' => '— انتخاب —',
                            'option_none_value' => '',
                            'hide_empty' => false,
                            'echo' => 0,
                        ] ) );
                        ?>
                    </td>
                </tr>
                <tr>
                    <th>کاربر<span style="color:#d00">*</span></th>
                    <td>
                        <select name="user_id" class="crm-select2" required>
                            <option value="">— انتخاب —</option>
                            <?php foreach ( get_users() as $u ) {
                                printf( '<option value="%d">%s</option>', $u->ID, esc_html( $u->display_name ) );
                            } ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>امتیاز<span style="color:#d00">*</span></th>
                    <td><input type="number" name="points" step="1" required></td>
                </tr>
            </table>
            <?php submit_button( 'ذخیره', 'primary', 'crm_assign_points' ); ?>
        </form>
        <?php
    }

    public function competition_rankings_shortcode( $atts = [] ): string {
        global $wpdb;
        $a = shortcode_atts( [ 'id' => 0, 'weight' => 0 ], $atts, 'crm_competition_rankings' );
        $cid = intval( $a['id'] ?: ( $_GET['competition_id'] ?? 0 ) );
        $wt  = intval( $a['weight'] ?: ( $_GET['weight_class'] ?? 0 ) );
        $where = $cid ? $wpdb->prepare( 'WHERE competition_id=%d', $cid ) : 'WHERE 1=1';
        if ( $wt ) {
            $where .= $wpdb->prepare( ' AND weight_class=%d', $wt );
        }
        $rows = $wpdb->get_results( "SELECT user_id, SUM(points) pts FROM {$wpdb->prefix}crm_points $where GROUP BY user_id ORDER BY pts DESC" );
        if ( ! $rows ) {
            return '<p>امتیازی ثبت نشده است.</p>';
        }
        ob_start();
        ?>
        <table class="crm-rank-table">
            <thead><tr><th>#</th><th>کاربر</th><th>امتیاز</th></tr></thead><tbody>
            <?php $i = 1; foreach ( $rows as $r ) :
                $u   = get_userdata( $r->user_id );
                $img = get_user_meta( $r->user_id, 'personal_photo', true ) ?: get_avatar_url( $r->user_id ); ?>
                <tr>
                    <td><?php echo $i++; ?></td>
                    <td><img src="<?php echo esc_url( $img ); ?>" style="width:30px;height:30px;border-radius:50%;vertical-align:middle"> <?php echo esc_html( $u->display_name ); ?></td>
                    <td><?php echo intval( $r->pts ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    public function my_rankings_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<p>لطفاً وارد شوید.</p>';
        }
        global $wpdb;
        $uid    = get_current_user_id();
        $expiry = intval( $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days'" ) );
        $exp    = $expiry ? $wpdb->prepare( 'AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)', $expiry ) : '';
        $my     = $wpdb->get_results( $wpdb->prepare( "SELECT competition_id, weight_class, SUM(points) pts FROM {$wpdb->prefix}crm_points WHERE user_id=%d $exp GROUP BY competition_id, weight_class ORDER BY pts DESC", $uid ) );
        $all    = $wpdb->get_results( "SELECT user_id, SUM(points) pts FROM {$wpdb->prefix}crm_points WHERE 1=1 $exp GROUP BY user_id ORDER BY pts DESC" );
        $overall_pts  = array_sum( wp_list_pluck( $my, 'pts' ) );
        $overall_rank = 0;
        foreach ( $all as $idx => $row ) {
            if ( (int) $row->user_id === $uid ) {
                $overall_rank = $idx + 1;
                break;
            }
        }
        ob_start();
        ?>
        <div class="crm-my-rank">
            <h3>جایگاه کلی من</h3>
            <p><strong>امتیاز کل:</strong> <?php echo $overall_pts; ?> ‖ <strong>رتبه:</strong> <?php echo $overall_rank; ?></p>
            <h3>جزئیات امتیازات</h3>
            <table>
                <thead><tr><th>#</th><th>مسابقه</th><th>کلاس وزنی</th><th>امتیاز</th></tr></thead><tbody>
                <?php $i = 1; foreach ( $my as $row ) :
                    $title = get_the_title( $row->competition_id );
                    $term  = get_term( $row->weight_class, 'weight_class' ); ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo esc_html( $title ); ?></td>
                        <td><?php echo esc_html( $term->name ?? '—' ); ?></td>
                        <td><?php echo intval( $row->pts ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
