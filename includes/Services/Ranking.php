<?php
namespace IMAOCustom\Services;

use WP_Term;
use WP_User;

class Ranking {
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_MANUAL_ATTENDEES = '_manual_attendees';
    private const AJAX_ACTION = 'crm_assign_points_ajax';
    private static bool $install_checked = false;

    public function register(): void {
        register_activation_hook( IMAO_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( IMAO_PLUGIN_FILE, [ $this, 'deactivate' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_shortcode( 'crm_competition_rankings', [ $this, 'competition_rankings_shortcode' ] );
        add_shortcode( 'crm_my_rankings', [ $this, 'my_rankings_shortcode' ] );
        add_action( 'init', [ $this, 'register_endpoint' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'woocommerce_account_my-rankings_endpoint', fn() => print do_shortcode( '[crm_my_rankings]' ) );
        add_action( 'plugins_loaded', [ $this, 'maybe_install' ] );
        add_action( 'wp_ajax_crm_competition_weight_classes', [ $this, 'ajax_competition_weight_classes' ] );
        add_action( 'wp_ajax_crm_competition_attendees', [ $this, 'ajax_competition_attendees' ] );
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
        $this->register_endpoint();
        flush_rewrite_rules();
    }

    public function maybe_install(): void {
        if ( self::$install_checked ) {
            return;
        }
        self::$install_checked = true;

        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset        = $wpdb->get_charset_collate();
        $points_table   = $wpdb->prefix . 'crm_points';
        $settings_table = $wpdb->prefix . 'crm_settings';

        $sql_points = "CREATE TABLE {$points_table} (
                id bigint unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint unsigned NOT NULL,
                competition_id bigint unsigned NOT NULL,
                weight_class bigint unsigned NOT NULL,
                points int NOT NULL DEFAULT 0,
                assigned_date datetime NOT NULL,
                PRIMARY KEY (id),
                KEY idx_comp (competition_id),
                KEY idx_user (user_id)
        ) {$charset};";

        $sql_settings = "CREATE TABLE {$settings_table} (
                opt_key varchar(60) NOT NULL PRIMARY KEY,
                opt_val varchar(191) NOT NULL
        ) {$charset};";

        if ( ! $this->table_exists( $points_table ) || ! $this->table_exists( $settings_table ) ) {
            dbDelta( $sql_points );
            dbDelta( $sql_settings );
        }

        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT opt_val FROM {$settings_table} WHERE opt_key = %s LIMIT 1",
                'points_expiry_days'
            )
        );

        if ( $existing === null ) {
            $wpdb->insert(
                $settings_table,
                [ 'opt_key' => 'points_expiry_days', 'opt_val' => '365' ],
                [ '%s', '%s' ]
            );
        }
    }

    private function table_exists( string $table_name ): bool {
        global $wpdb;

        $pattern = $wpdb->esc_like( $table_name );
        $found   = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $pattern
            )
        );

        return is_string( $found ) && strcasecmp( $found, $table_name ) === 0;
    }

    public function register_endpoint(): void {
        add_rewrite_endpoint( 'my-rankings', EP_ROOT | EP_PAGES );
    }

    public function deactivate(): void {
        flush_rewrite_rules();
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
            $this->render_rank_tab();
        } else {
            $this->render_assign_tab();
        }
        echo '</div>';
    }

    private function render_settings_tab(): void {
        global $wpdb;
        if ( isset( $_POST['save_settings'] ) ) {
            check_admin_referer( 'crm_points_settings' );
            $days = $this->sanitize_expiry_days( $_POST['expiry_days'] ?? '' );
            $wpdb->replace( $wpdb->prefix . 'crm_settings', [ 'opt_key' => 'points_expiry_days', 'opt_val' => (string) $days ], [ '%s', '%s' ] );
            echo '<div class="updated"><p>ذخیره شد.</p></div>';
        }
        $expiry = intval( $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" ) );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'crm_points_settings' ); ?>
            <table class="form-table striped">
                <tr>
                    <th scope="row">انقضای امتیازها (روز)</th>
                    <td><input type="number" name="expiry_days" value="<?php echo $expiry; ?>" min="0"><p class="description">0 = بدون انقضا</p></td>
                </tr>
            </table>
            <?php submit_button( 'ذخیره', 'primary', 'save_settings' ); ?>
        </form>
        <?php
    }

    private function sanitize_expiry_days( $value ): int {
        if ( is_array( $value ) ) {
            $value = reset( $value );
        }
        if ( ! is_scalar( $value ) ) {
            return 0;
        }
        $value = (string) $value;
        if ( function_exists( 'wp_unslash' ) ) {
            $value = wp_unslash( $value );
        }
        $value = strtr(
            $value,
            [
                '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            ]
        );
        $value = preg_replace( '/[^0-9]/u', '', $value );
        if ( $value === '' ) {
            return 0;
        }
        return max( 0, (int) $value );
    }

    private function render_assign_tab(): void {
        global $wpdb;
        $selected_competition = intval( $_POST['competition_id'] ?? 0 );
        $selected_weight      = intval( $_POST['weight_class'] ?? 0 );
        $selected_user        = intval( $_POST['user_id'] ?? 0 );
        $show_all_attendees   = ! empty( $_POST['crm_show_all_attendees'] );

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
            <table class="form-table striped">
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
                                printf(
                                    '<option value="%d"%s>%s</option>',
                                    $c->ID,
                                    selected( $selected_competition, $c->ID, false ),
                                    esc_html( $c->post_title )
                                );
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>دسته وزنی<span style="color:#d00">*</span></th>
                    <td>
                        <?php
                        $weight_options = $selected_competition
                            ? $this->get_competition_weight_options( $selected_competition )
                            : [];
                        ?>
                        <select
                            name="weight_class"
                            class="crm-select2"
                            data-selected="<?php echo esc_attr( $selected_weight ?: '' ); ?>"
                            data-placeholder="<?php esc_attr_e( '— انتخاب —', 'imao-custom-plugin' ); ?>"
                            required
                        >
                            <?php
                            if ( $weight_options ) {
                                echo '<option value="">' . esc_html__( '— انتخاب —', 'imao-custom-plugin' ) . '</option>';
                                foreach ( $weight_options as $option ) {
                                    printf(
                                        '<option value="%d"%s>%s</option>',
                                        $option['id'],
                                        selected( $selected_weight, $option['id'], false ),
                                        esc_html( $option['label'] )
                                    );
                                }
                            } else {
                                echo '<option value="">' . esc_html( '— ابتدا مسابقه را انتخاب کنید —' ) . '</option>';
                            }
                            ?>
                        </select>
                        <p>
                            <label>
                                <input type="checkbox" name="crm_show_all_attendees" id="crm-show-all-attendees" value="1" <?php checked( $show_all_attendees ); ?>>
                                نمایش تمام شرکت‌کنندگان این مسابقه
                            </label>
                            <span class="description">در صورت فعال‌سازی، همهٔ شرکت‌کنندگان صرف‌نظر از دسته وزنی نمایش داده می‌شوند.</span>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>کاربر<span style="color:#d00">*</span></th>
                    <td>
                        <?php
                        $attendee_options = [];
                        if ( $selected_competition && ( $selected_weight || $show_all_attendees ) ) {
                            $attendee_options = $this->get_competition_attendees( $selected_competition, $selected_weight, $show_all_attendees );
                        }
                        ?>
                        <select
                            name="user_id"
                            class="crm-select2"
                            data-selected="<?php echo esc_attr( $selected_user ?: '' ); ?>"
                            data-placeholder="<?php esc_attr_e( '— انتخاب —', 'imao-custom-plugin' ); ?>"
                            required
                        >
                            <?php
                            if ( $attendee_options ) {
                                echo '<option value="">' . esc_html__( '— انتخاب —', 'imao-custom-plugin' ) . '</option>';
                                foreach ( $attendee_options as $option ) {
                                    printf(
                                        '<option value="%d"%s>%s</option>',
                                        $option['id'],
                                        selected( $selected_user, $option['id'], false ),
                                        esc_html( $option['label'] )
                                    );
                                }
                            } else {
                                echo '<option value="">' . esc_html( '— ابتدا دسته وزنی را انتخاب کنید —' ) . '</option>';
                            }
                            ?>
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

    public function enqueue_admin_assets( string $hook ): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->id !== 'users_page_crm-points-manager' ) {
            return;
        }
        $url       = plugin_dir_url( IMAO_PLUGIN_FILE );
        $path      = plugin_dir_path( IMAO_PLUGIN_FILE );
        $script    = 'assets/js/ranking-assign.js';
        $version   = is_file( $path . $script ) ? (string) filemtime( $path . $script ) : '1.0.0';
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '4.0.13' );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '4.0.13', true );
        wp_add_inline_script(
            'imao-select2',
            'jQuery(function($){$("select.crm-select2").select2({dir:"rtl",width:"resolve"});});'
        );
        wp_enqueue_script( 'imao-ranking-assign', $url . $script, [ 'jquery', 'imao-select2' ], $version, true );
        wp_localize_script(
            'imao-ranking-assign',
            'crmAssignPoints',
            [
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
                'actions'  => [
                    'weights'   => 'crm_competition_weight_classes',
                    'attendees' => 'crm_competition_attendees',
                ],
                'i18n'     => [
                    'loading'             => 'در حال بارگذاری…',
                    'select_competition'  => '— ابتدا مسابقه را انتخاب کنید —',
                    'select_weight'       => '— ابتدا دسته وزنی را انتخاب کنید —',
                    'no_weights'          => 'دستهٔ وزنی برای این مسابقه ثبت نشده است.',
                    'no_attendees'        => 'شرکت‌کننده‌ای یافت نشد.',
                    'user_placeholder'    => '— انتخاب —',
                    'weight_placeholder'  => '— انتخاب —',
                    'error_generic'       => 'بروز خطا. لطفاً دوباره تلاش کنید.',
                ],
            ]
        );
    }

    public function ajax_competition_weight_classes(): void {
        $this->verify_ajax_request();
        $competition_id = intval( $_POST['competition_id'] ?? 0 );
        if ( ! $competition_id ) {
            wp_send_json_success( [ 'weights' => [] ] );
        }
        $weights = $this->get_competition_weight_options( $competition_id );
        wp_send_json_success( [ 'weights' => $weights ] );
    }

    public function ajax_competition_attendees(): void {
        $this->verify_ajax_request();
        $competition_id = intval( $_POST['competition_id'] ?? 0 );
        $weight_class   = intval( $_POST['weight_class'] ?? 0 );
        $include_all    = ! empty( $_POST['include_all'] );
        if ( ! $competition_id || ( ! $include_all && ! $weight_class ) ) {
            wp_send_json_success( [ 'attendees' => [] ] );
        }
        $attendees = $this->get_competition_attendees( $competition_id, $weight_class, $include_all );
        wp_send_json_success( [ 'attendees' => $attendees ] );
    }

    private function verify_ajax_request(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }
        $nonce = $_POST['nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, self::AJAX_ACTION ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ], 400 );
        }
    }

    /**
     * @return array<int,array{id:int,label:string}>
     */
    private function get_competition_weight_options( int $competition_id ): array {
        if ( ! $competition_id ) {
            return [];
        }
        $terms = wp_get_post_terms( $competition_id, 'age_category', [ 'hide_empty' => false ] );
        if ( ! is_array( $terms ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $terms ) ) ) {
            return [];
        }
        $parents  = [];
        $children = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof WP_Term ) {
                continue;
            }
            $term_id   = (int) $term->term_id;
            $parent_id = (int) $term->parent;
            if ( ! $term_id ) {
                continue;
            }
            if ( $parent_id ) {
                $children[ $parent_id ][ $term_id ] = $term;
            } else {
                $parents[ $term_id ] = $term;
            }
        }
        $options = [];
        foreach ( $children as $parent_id => $child_terms ) {
            $parent_name = '';
            if ( isset( $parents[ $parent_id ] ) ) {
                $parent_name = (string) $parents[ $parent_id ]->name;
            } else {
                $parent_term = get_term( $parent_id, 'age_category' );
                if ( $parent_term && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $parent_term ) ) ) {
                    $parent_name = (string) $parent_term->name;
                }
            }
            foreach ( $child_terms as $term_id => $term ) {
                $label = trim( $parent_name !== '' ? $parent_name . ' - ' . $term->name : $term->name );
                $options[] = [
                    'id'    => $term_id,
                    'label' => $label,
                ];
            }
        }
        if ( ! $options && $parents ) {
            foreach ( $parents as $term_id => $term ) {
                $options[] = [
                    'id'    => $term_id,
                    'label' => (string) $term->name,
                ];
            }
        }
        usort(
            $options,
            static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] )
        );
        return $options;
    }

    /**
     * @return array<int,array{id:int,label:string,weight:string}>
     */
    private function get_competition_attendees( int $competition_id, int $weight_class, bool $include_all ): array {
        if ( ! $competition_id ) {
            return [];
        }
        $users = $this->load_competition_users( $competition_id );
        if ( ! $users ) {
            return [];
        }
        $meta_map      = $this->get_registration_meta_map( $competition_id, $users );
        $target_weight = '';
        if ( $weight_class ) {
            $weight_term = get_term( $weight_class, 'age_category' );
            if ( $weight_term && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $weight_term ) ) ) {
                $target_weight = (string) $weight_term->name;
            }
        }
        $target_normalized = $this->normalize_string( $target_weight );
        $rows              = [];
        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }
            $uid         = (int) $user->ID;
            $weight_name = '';
            if ( isset( $meta_map[ $uid ]['weight_class'] ) ) {
                $weight_name = trim( (string) $meta_map[ $uid ]['weight_class'] );
            }
            $weight_normalized = $this->normalize_string( $weight_name );
            if ( $target_normalized !== '' && ! $include_all && $weight_normalized !== $target_normalized ) {
                continue;
            }
            if ( $target_normalized !== '' && ! $include_all && $weight_normalized === '' ) {
                continue;
            }
            if ( ! $include_all && $target_normalized === '' ) {
                continue;
            }
            $label = $user->display_name;
            if ( $weight_name !== '' ) {
                $label .= ' (' . $weight_name . ')';
            }
            $rows[] = [
                'id'     => $uid,
                'label'  => $label,
                'weight' => $weight_name,
            ];
        }
        usort(
            $rows,
            static fn( array $a, array $b ): int => strcasecmp( $a['label'], $b['label'] )
        );
        return $rows;
    }

    /**
     * @return array<int,WP_User>
     */
    private function load_competition_users( int $competition_id ): array {
        $user_map  = [];
        $product_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        if ( $product_id && function_exists( 'wc_get_orders' ) ) {
            $order_ids = $this->get_order_ids_for_product( $product_id );
            if ( $order_ids ) {
                $orders = wc_get_orders( [
                    'limit'  => -1,
                    'status' => [ 'processing', 'completed' ],
                    'include'=> $order_ids,
                ] );
                foreach ( $orders as $order ) {
                    if ( ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) ) {
                        continue;
                    }
                    $uid = (int) $order->get_user_id();
                    if ( $uid > 0 ) {
                        $user_map[ $uid ] = true;
                    }
                }
            }
        }
        $manual = get_post_meta( $competition_id, self::META_MANUAL_ATTENDEES, true );
        if ( is_array( $manual ) ) {
            foreach ( $manual as $uid ) {
                $uid = (int) $uid;
                if ( $uid > 0 ) {
                    $user_map[ $uid ] = true;
                }
            }
        }
        $users = [];
        foreach ( array_keys( $user_map ) as $uid ) {
            $user = get_user_by( 'id', $uid );
            if ( $user instanceof WP_User ) {
                $users[ $uid ] = $user;
            }
        }
        return array_values( $users );
    }

    /**
     * @param array<int,WP_User> $users
     * @return array<int,array<string,string>>
     */
    private function get_registration_meta_map( int $competition_id, array $users ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return [];
        }
        $product_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        if ( ! $product_id ) {
            return [];
        }
        $meta_map  = [];
        $order_ids = $this->get_order_ids_for_product( $product_id );
        if ( $order_ids ) {
            $orders = wc_get_orders( [
                'limit'  => -1,
                'status' => [ 'processing', 'completed' ],
                'include'=> $order_ids,
            ] );
            $this->extract_registration_meta_from_orders( $orders, $product_id, $meta_map );
            return $meta_map;
        }
        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }
            $uid = (int) $user->ID;
            if ( ! $uid ) {
                continue;
            }
            $orders = wc_get_orders( [
                'limit'       => -1,
                'status'      => [ 'processing', 'completed' ],
                'customer_id' => $uid,
            ] );
            $this->extract_registration_meta_from_orders( $orders, $product_id, $meta_map );
        }
        return $meta_map;
    }

    /**
     * @return int[]
     */
    private function get_order_ids_for_product( int $product_id ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) ) {
            return [];
        }
        $sql = $wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items oi
             JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
             WHERE oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value = %d",
            $product_id
        );
        $results = $wpdb->get_col( $sql );
        if ( ! $results ) {
            return [];
        }
        return array_map( 'intval', $results );
    }

    /**
     * @param iterable<int,object> $orders
     * @param int $product_id
     * @param array<int,array<string,string>> $meta_map
     */
    private function extract_registration_meta_from_orders( iterable $orders, int $product_id, array &$meta_map ): void {
        foreach ( $orders as $order ) {
            if ( ! is_object( $order ) || ! method_exists( $order, 'get_user_id' ) || ! method_exists( $order, 'get_items' ) ) {
                continue;
            }
            $uid = (int) $order->get_user_id();
            if ( ! $uid ) {
                continue;
            }
            foreach ( $order->get_items() as $item ) {
                if ( ! is_object( $item ) || ! method_exists( $item, 'get_product_id' ) ) {
                    continue;
                }
                if ( (int) $item->get_product_id() !== $product_id ) {
                    continue;
                }
                $weight = trim( (string) $item->get_meta( 'دسته وزنی', true ) );
                $age    = trim( (string) $item->get_meta( 'رده سنی', true ) );
                if ( ! isset( $meta_map[ $uid ] ) ) {
                    $meta_map[ $uid ] = [ 'weight_class' => $weight, 'age_category' => $age ];
                    continue;
                }
                if ( $weight !== '' && ( $meta_map[ $uid ]['weight_class'] ?? '' ) === '' ) {
                    $meta_map[ $uid ]['weight_class'] = $weight;
                }
                if ( $age !== '' && ( $meta_map[ $uid ]['age_category'] ?? '' ) === '' ) {
                    $meta_map[ $uid ]['age_category'] = $age;
                }
            }
        }
    }

    private function normalize_string( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) {
            return '';
        }
        $value = preg_replace( '/\s+/u', ' ', $value );
        if ( function_exists( 'mb_strtolower' ) ) {
            $value = mb_strtolower( $value, 'UTF-8' );
        } else {
            $value = strtolower( $value );
        }
        return $value;
    }

    private function get_ranking_rows( int $competition_id = 0, int $weight_class = 0 ): array {
        global $wpdb;
        $where = 'WHERE 1=1';
        if ( $competition_id ) {
            $where .= $wpdb->prepare( ' AND competition_id=%d', $competition_id );
        }
        if ( $weight_class ) {
            $where .= $wpdb->prepare( ' AND weight_class=%d', $weight_class );
        }
        $expiry_days = (int) $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" );
        if ( $expiry_days ) {
            $where .= $wpdb->prepare( ' AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)', $expiry_days );
        }
        return $wpdb->get_results( "SELECT user_id, SUM(points) AS pts FROM {$wpdb->prefix}crm_points $where GROUP BY user_id ORDER BY pts DESC" );
    }

    public function competition_rankings_shortcode( $atts = [] ): string {
        $a   = shortcode_atts( [ 'id' => 0, 'weight' => 0 ], $atts, 'crm_competition_rankings' );
        $cid = intval( $a['id'] ?: ( $_GET['competition_id'] ?? 0 ) );
        $wt  = intval( $a['weight'] ?: ( $_GET['weight_class'] ?? 0 ) );
        if ( ! $cid ) {
            return '<p>مسابقه نامشخص است.</p>';
        }
        $rows = $this->get_ranking_rows( $cid, $wt );
        if ( ! $rows ) {
            return '<p>امتیازی ثبت نشده است.</p>';
        }
        ob_start();
        ?>
        <table class="crm-rank-table striped">
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

    private function render_rank_tab(): void {
        $comp_id = intval( $_GET['competition_id'] ?? 0 );
        $w_term  = intval( $_GET['weight_class'] ?? 0 );

        echo '<form method="get" style="margin-bottom:15px">';
        echo '<input type="hidden" name="page" value="crm-points-manager">';
        echo '<input type="hidden" name="tab" value="rank">';

        echo '<select name="competition_id" class="crm-select2" style="min-width:200px">';
        echo '<option value="0">همه مسابقات</option>';
        foreach ( get_posts( [
            'post_type'      => 'competition',
            'posts_per_page' => -1,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
        ] ) as $c ) {
            printf( '<option value="%d"%s>%s</option>', $c->ID, selected( $comp_id, $c->ID, false ), esc_html( $c->post_title ) );
        }
        echo '</select> ';

        $terms = get_terms([
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
        ]);
        echo '<select name="weight_class" class="crm-select2" style="min-width:200px">';
        echo '<option value="0">همه کلاس‌ها</option>';
        foreach ($terms as $t) {
            if ($t->parent) {
                $parent = get_term($t->parent, 'age_category');
                $label  = ($parent ? $parent->name . ' - ' : '') . $t->name;
                printf('<option value="%d"%s>%s</option>', $t->term_id, selected($w_term, $t->term_id, false), esc_html($label));
            }
        }
        echo '</select>';

        submit_button( 'نمایش', 'secondary', '', false );
        echo '</form>';

        $rows = $this->get_ranking_rows( $comp_id, $w_term );
        if ( ! $rows ) {
            echo '<p>موردی یافت نشد.</p>';
            return;
        }
        $grand_total = array_sum( wp_list_pluck( $rows, 'pts' ) );
        echo '<p style="font-weight:600;margin:10px 0;"> مجموع امتیازهای فعال در این نما: '
             . esc_html( number_format_i18n( $grand_total ) )
             . '</p>';

        echo '<table class="widefat striped"><thead><tr><th>#</th><th>کاربر</th><th>امتیاز</th></tr></thead><tbody>';
        $pos = 1;
        foreach ( $rows as $row ) {
            $user = get_userdata( $row->user_id );
            $img  = get_user_meta( $row->user_id, 'personal_photo', true ) ?: get_avatar_url( $row->user_id );
            printf( '<tr><td>%d</td><td><img src="%s" style="width:30px;border-radius:50%%;vertical-align:middle"> %s</td><td>%d</td></tr>',
                $pos++, esc_url( $img ), esc_html( $user ? $user->display_name : '—' ), (int) $row->pts );
        }
        echo '</tbody></table>';
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
        <div class="sd-container crm-my-rank">
            <div class="sd-header">امتیازات من</div>
            <p style="margin-bottom: 20px"><strong>امتیاز کل:</strong> <?php echo $overall_pts; ?> ‖ <strong>رتبه:</strong> <?php echo $overall_rank; ?></p>
            <h3>جزئیات امتیازات</h3>
            <table class="shop_table shop_table_responsive striped">
                <thead><tr><th>#</th><th>مسابقه</th><th>دسته وزنی</th><th>امتیاز</th></tr></thead><tbody>
                <?php $i = 1; foreach ( $my as $row ) :
                    $title = get_the_title( $row->competition_id );
                    $term  = get_term( $row->weight_class, 'age_category' ); ?>
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
