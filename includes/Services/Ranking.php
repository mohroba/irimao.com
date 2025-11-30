<?php
namespace IMAOCustom\Services;

use IMAOCustom\Services\Widgets\CompetitionRankingsWidget;
use IMAOCustom\Helpers\UserMeta;
use WP_Term;
use WP_User;

class Ranking {
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_MANUAL_ATTENDEES = '_manual_attendees';
    private const AJAX_ACTION = 'crm_assign_points_ajax';
    private static bool $install_checked = false;
    private static bool $overview_style_printed = false;

    /**
     * Write an entry to the PHP error log to aid with production debugging.
     *
     * @param array<string,mixed> $context
     */
    private function log_debug( string $message, array $context = [] ): void {
        $prefix = '[IMAOCustom\\Ranking] ';
        if ( $context ) {
            $encoded = function_exists( 'wp_json_encode' )
                ? wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR )
                : json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
            if ( is_string( $encoded ) && $encoded !== '' ) {
                $message .= ' ' . $encoded;
            }
        }

        error_log( $prefix . $message );
    }

    /** @var array<string,string> */
    private array $gender_label_cache = [
        'men'   => 'مردان',
        'women' => 'زنان',
    ];

    public function register(): void {
        register_activation_hook( IMAO_PLUGIN_FILE, [ $this, 'activate' ] );
        register_deactivation_hook( IMAO_PLUGIN_FILE, [ $this, 'deactivate' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_shortcode( 'crm_competition_rankings', [ $this, 'competition_rankings_shortcode' ] );
        add_shortcode( 'crm_my_rankings', [ $this, 'my_rankings_shortcode' ] );
        add_shortcode( 'crm_rankings_overview', [ $this, 'rankings_overview_shortcode' ] );
        add_action( 'init', [ $this, 'register_endpoint' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_crm_ranking_table', [ $this, 'ajax_ranking_table' ] );
        add_action( 'woocommerce_account_my-rankings_endpoint', fn() => print do_shortcode( '[crm_my_rankings]' ) );
        add_action( 'plugins_loaded', [ $this, 'maybe_install' ] );
        add_action( 'wp_ajax_crm_competition_weight_classes', [ $this, 'ajax_competition_weight_classes' ] );
        add_action( 'wp_ajax_crm_competition_attendees', [ $this, 'ajax_competition_attendees' ] );
        add_action( 'elementor/widgets/register', [ $this, 'register_elementor_widget' ] );
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

    /**
     * Register Elementor widgets when Elementor is available.
     *
     * @param object $widgets_manager Widget manager instance provided by Elementor.
     */
    public function register_elementor_widget( $widgets_manager ): void {
        if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
            return;
        }

        if ( ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
            return;
        }

        $widgets_manager->register( new CompetitionRankingsWidget() );
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
        $selected_competition    = max( 0, intval( $_POST['competition_id'] ?? 0 ) );
        $selected_weight         = max( 0, intval( $_POST['weight_class'] ?? 0 ) );
        $selected_user           = max( 0, intval( $_POST['user_id'] ?? 0 ) );
        $show_all_attendees      = ! empty( $_POST['crm_show_all_attendees'] );
        $assignment_weight_input = max( 0, intval( $_POST['assigned_weight_class'] ?? 0 ) );

        if ( isset( $_POST['crm_assign_points'] ) ) {
            check_admin_referer( 'crm_assign_points' );
            $user_id        = max( 0, intval( $_POST['user_id'] ?? 0 ) );
            $competition_id = max( 0, intval( $_POST['competition_id'] ?? 0 ) );
            $points         = intval( $_POST['points'] ?? 0 );
            $resolved_weight = $assignment_weight_input;

            if ( ! $competition_id || ! $user_id ) {
                echo '<div class="error"><p>تمام فیلدها الزامی است.</p></div>';
            } else {
                if ( ! $resolved_weight ) {
                    $resolved_weight = $this->resolve_user_weight_term_id( $competition_id, $user_id );
                }

                if ( ! $resolved_weight ) {
                    echo '<div class="error"><p>دسته وزنی شرکت‌کننده یافت نشد. لطفاً ابتدا وزن ثبت‌شده او را بررسی کنید.</p></div>';
                } else {
                    $wpdb->insert( $wpdb->prefix . 'crm_points', [
                        'user_id'        => $user_id,
                        'competition_id' => $competition_id,
                        'weight_class'   => $resolved_weight,
                        'points'         => $points,
                        'assigned_date'  => current_time( 'mysql' ),
                    ], [ '%d', '%d', '%d', '%d', '%s' ] );
                    $assignment_weight_input = $resolved_weight;
                    echo '<div class="updated"><p>امتیاز ثبت شد.</p></div>';
                }
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
                            <?php echo $selected_competition ? '' : 'disabled'; ?>
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
                            <?php echo $selected_competition ? '' : 'disabled'; ?>
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
                        <input type="hidden" name="assigned_weight_class" id="crm-assigned-weight-class" value="<?php echo esc_attr( $assignment_weight_input ?: '' ); ?>">
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
        $tab = isset( $_GET['tab'] ) ? (string) $_GET['tab'] : 'assign';
        $url       = plugin_dir_url( IMAO_PLUGIN_FILE );
        $path      = plugin_dir_path( IMAO_PLUGIN_FILE );
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '4.0.13' );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '4.0.13', true );
        wp_add_inline_script(
            'imao-select2',
            'jQuery(function($){$("select.crm-select2").select2({dir:"rtl",width:"resolve"});});'
        );

        if ( $tab === 'assign' ) {
            $assign_script  = 'assets/js/ranking-assign.js';
            $assign_version = is_file( $path . $assign_script ) ? (string) filemtime( $path . $assign_script ) : '1.0.0';
            wp_enqueue_script( 'imao-ranking-assign', $url . $assign_script, [ 'jquery', 'imao-select2' ], $assign_version, true );
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
            return;
        }

        if ( $tab === 'rank' ) {
            $dt_script  = 'assets/js/jquery.dataTables.min.js';
            $dt_style   = 'assets/css/jquery.dataTables.min.css';
            $rank_js    = 'assets/js/ranking-rank.js';
            $dt_version = is_file( $path . $dt_script ) ? (string) filemtime( $path . $dt_script ) : '1.13.8';
            $rank_ver   = is_file( $path . $rank_js ) ? (string) filemtime( $path . $rank_js ) : '1.0.0';
            wp_enqueue_style( 'imao-dt', $url . $dt_style, [], $dt_version );
            wp_enqueue_script( 'imao-dt', $url . $dt_script, [ 'jquery' ], $dt_version, true );
            wp_enqueue_script( 'imao-ranking-rank', $url . $rank_js, [ 'jquery', 'imao-dt', 'imao-select2' ], $rank_ver, true );
            wp_localize_script(
                'imao-ranking-rank',
                'crmRankingsTable',
                [
                    'ajax_url' => admin_url( 'admin-ajax.php' ),
                    'nonce'    => wp_create_nonce( self::AJAX_ACTION ),
                    'actions'  => [ 'table' => 'crm_ranking_table' ],
                ]
            );
        }
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
            $normalized_parent = $this->normalize_string( $parent_name );
            foreach ( $child_terms as $term_id => $term ) {
                $label             = trim( $parent_name !== '' ? $parent_name . ' - ' . $term->name : $term->name );
                $term_name         = (string) $term->name;
                $options[]         = [
                    'id'                => $term_id,
                    'label'             => $label,
                    'term_name'         => $term_name,
                    'parent_id'         => $parent_id,
                    'parent_name'       => $parent_name,
                    'normalized_label'  => $this->normalize_string( $label ),
                    'normalized_term'   => $this->normalize_string( $term_name ),
                    'normalized_parent' => $normalized_parent,
                ];
            }
        }

        if ( ! $options && $parents ) {
            foreach ( $parents as $term_id => $term ) {
                $term_name = (string) $term->name;
                $options[] = [
                    'id'                => $term_id,
                    'label'             => $term_name,
                    'term_name'         => $term_name,
                    'parent_id'         => (int) $term->parent,
                    'parent_name'       => '',
                    'normalized_label'  => $this->normalize_string( $term_name ),
                    'normalized_term'   => $this->normalize_string( $term_name ),
                    'normalized_parent' => '',
                ];
            }
        }

        usort(
            $options,
            static fn( array $a, array $b ): int => strcasecmp( (string) ( $a['label'] ?? '' ), (string) ( $b['label'] ?? '' ) )
        );

        return $options;
    }

    /**
     * @param array<int,array<string,mixed>> $options
     *
     * @return array<int,array<string,mixed>>
     */
    private function index_weight_options( array $options ): array {
        $indexed = [];
        foreach ( $options as $option ) {
            $id = isset( $option['id'] ) ? (int) $option['id'] : 0;
            if ( $id > 0 ) {
                $indexed[ $id ] = $option;
            }
        }

        return $indexed;
    }

    /**
     * Attempt to resolve the registered weight option for a given attendee meta row.
     *
     * @param array<int,array<string,mixed>> $options
     * @param array<string,mixed>            $meta
     */
    private function match_weight_option( array $options, array $meta ): int {
        $requested_id = isset( $meta['weight_class_term'] ) ? (int) $meta['weight_class_term'] : 0;
        if ( $requested_id > 0 ) {
            foreach ( $options as $option ) {
                if ( (int) ( $option['id'] ?? 0 ) === $requested_id ) {
                    return $requested_id;
                }
            }
        }

        $weight_name = $this->normalize_string( (string) ( $meta['weight_class'] ?? '' ) );
        if ( $weight_name === '' ) {
            return 0;
        }

        $age_name    = $this->normalize_string( (string) ( $meta['age_category'] ?? '' ) );
        $age_term_id = isset( $meta['age_category_term'] ) ? (int) $meta['age_category_term'] : 0;

        foreach ( $options as $option ) {
            $option_id = isset( $option['id'] ) ? (int) $option['id'] : 0;
            if ( $option_id <= 0 ) {
                continue;
            }

            $normalized_term   = (string) ( $option['normalized_term'] ?? '' );
            $normalized_label  = (string) ( $option['normalized_label'] ?? $normalized_term );
            $normalized_parent = (string) ( $option['normalized_parent'] ?? '' );
            $parent_id         = isset( $option['parent_id'] ) ? (int) $option['parent_id'] : 0;

            $weight_matches = $this->strings_overlap( $weight_name, $normalized_term )
                || $this->strings_overlap( $weight_name, $normalized_label );

            if ( ! $weight_matches ) {
                continue;
            }

            if ( $age_term_id && $parent_id && $age_term_id !== $parent_id ) {
                continue;
            }

            if ( $age_name !== '' && $normalized_parent !== '' && ! $this->strings_overlap( $age_name, $normalized_parent ) ) {
                continue;
            }

            return $option_id;
        }

        return 0;
    }

    private function strings_overlap( string $a, string $b ): bool {
        if ( $a === '' || $b === '' ) {
            return false;
        }

        if ( $a === $b ) {
            return true;
        }

        if ( strpos( $a, $b ) !== false ) {
            return true;
        }

        return strpos( $b, $a ) !== false;
    }

    /**
     * @return array<int,array{id:int,label:string,weight:string,weight_term_id:int}>
     */
    private function get_competition_attendees( int $competition_id, int $weight_class, bool $include_all ): array {
        if ( ! $competition_id ) {
            $this->log_debug( 'Skipping attendees lookup: empty competition ID.' );
            return [];
        }

        $this->log_debug(
            'Fetching competition attendees.',
            [
                'competition_id' => $competition_id,
                'weight_class'   => $weight_class,
                'include_all'    => $include_all,
            ]
        );

        $users = $this->load_competition_users( $competition_id );
        if ( ! $users ) {
            $this->log_debug( 'No users resolved for competition.', [ 'competition_id' => $competition_id ] );
            return [];
        }

        $meta_map       = $this->get_registration_meta_map( $competition_id, $users );
        $weight_options = $this->get_competition_weight_options( $competition_id );
        $weight_index   = $this->index_weight_options( $weight_options );

        $this->log_debug(
            'Prepared attendee context.',
            [
                'competition_id'    => $competition_id,
                'resolved_users'    => count( $users ),
                'meta_map_count'    => count( $meta_map ),
                'weight_option_cnt' => count( $weight_options ),
            ]
        );

        $target_label = '';
        if ( $weight_class && isset( $weight_index[ $weight_class ] ) ) {
            $target_label = (string) ( $weight_index[ $weight_class ]['label'] ?? '' );
        } elseif ( $weight_class ) {
            $weight_term = get_term( $weight_class, 'age_category' );
            if ( $weight_term && ( ! function_exists( 'is_wp_error' ) || ! is_wp_error( $weight_term ) ) ) {
                $target_label = (string) $weight_term->name;
            }
        }
        $target_normalized = $this->normalize_string( $target_label );

        $rows = [];
        foreach ( $users as $user ) {
            if ( ! $user instanceof WP_User ) {
                continue;
            }

            $uid  = (int) $user->ID;
            $meta = $meta_map[ $uid ] ?? [];

            $weight_name     = trim( (string) ( $meta['weight_class'] ?? '' ) );
            $weight_term_id  = $this->match_weight_option( $weight_options, $meta );
            $weight_label    = $weight_name;
            $weight_option   = $weight_term_id && isset( $weight_index[ $weight_term_id ] ) ? $weight_index[ $weight_term_id ] : null;
            if ( is_array( $weight_option ) ) {
                $weight_label = (string) ( $weight_option['label'] ?? $weight_label );
            }

            $weight_normalized = $this->normalize_string( $weight_label ?: $weight_name );

            if ( ! $include_all && $weight_class ) {
                if ( $weight_term_id ) {
                    if ( $weight_term_id !== $weight_class ) {
                        continue;
                    }
                } elseif ( $target_normalized !== '' ) {
                    if ( $weight_normalized === '' || ! $this->strings_overlap( $weight_normalized, $target_normalized ) ) {
                        continue;
                    }
                } else {
                    continue;
                }
            }

            $label = $user->display_name;
            if ( $weight_label !== '' ) {
                $label .= ' (' . $weight_label . ')';
            }

            $rows[] = [
                'id'             => $uid,
                'label'          => $label,
                'weight'         => $weight_label,
                'weight_term_id' => $weight_term_id,
            ];
        }

        usort(
            $rows,
            static fn( array $a, array $b ): int => strcasecmp( (string) $a['label'], (string) $b['label'] )
        );

        $this->log_debug(
            'Completed attendees lookup.',
            [
                'competition_id' => $competition_id,
                'weight_class'   => $weight_class,
                'include_all'    => $include_all,
                'result_count'   => count( $rows ),
            ]
        );

        return $rows;
    }

    private function resolve_user_weight_term_id( int $competition_id, int $user_id ): int {
        if ( ! $competition_id || ! $user_id ) {
            return 0;
        }

        $attendees = $this->get_competition_attendees( $competition_id, 0, true );
        foreach ( $attendees as $attendee ) {
            if ( (int) ( $attendee['id'] ?? 0 ) === $user_id ) {
                return max( 0, (int) ( $attendee['weight_term_id'] ?? 0 ) );
            }
        }

        return 0;
    }

    /**
     * @return array<int,WP_User>
     */
    private function load_competition_users( int $competition_id ): array {
        $this->log_debug( 'Resolving competition users.', [ 'competition_id' => $competition_id ] );

        $user_map  = [];
        $product_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        if ( $product_id && function_exists( 'wc_get_orders' ) ) {
            $this->log_debug( 'Competition linked to product.', [ 'competition_id' => $competition_id, 'product_id' => $product_id ] );
            $order_ids = $this->get_order_ids_for_product( $product_id );
            if ( $order_ids ) {
                $this->log_debug(
                    'Order IDs found for product.',
                    [
                        'competition_id' => $competition_id,
                        'product_id'     => $product_id,
                        'order_count'    => count( $order_ids ),
                    ]
                );
                $query_args = [
                    'limit'   => -1,
                    'include' => $order_ids,
                ];
                $statuses  = $this->get_relevant_order_statuses();
                if ( $statuses ) {
                    $query_args['status'] = $statuses;
                }
                $orders = wc_get_orders( $query_args );
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
            $this->log_debug(
                'Merging manually assigned attendees.',
                [
                    'competition_id' => $competition_id,
                    'manual_count'   => count( $manual ),
                ]
            );
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
        $this->log_debug(
            'Resolved competition user list.',
            [
                'competition_id' => $competition_id,
                'user_count'     => count( $users ),
            ]
        );
        return array_values( $users );
    }

    /**
     * @param array<int,WP_User> $users
     * @return array<int,array<string,string>>
     */
    private function get_registration_meta_map( int $competition_id, array $users ): array {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            $this->log_debug( 'Skipping registration meta map: WooCommerce unavailable.' );
            return [];
        }
        $product_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        if ( ! $product_id ) {
            $this->log_debug( 'Skipping registration meta map: no linked product.', [ 'competition_id' => $competition_id ] );
            return [];
        }
        $meta_map  = [];
        $order_ids = $this->get_order_ids_for_product( $product_id );
        if ( $order_ids ) {
            $query_args = [
                'limit'   => -1,
                'include' => $order_ids,
            ];
            $statuses  = $this->get_relevant_order_statuses();
            if ( $statuses ) {
                $query_args['status'] = $statuses;
            }
            $orders = wc_get_orders( $query_args );
            $this->extract_registration_meta_from_orders( $orders, $product_id, $meta_map );
            $this->log_debug(
                'Registration meta map populated from direct order lookup.',
                [
                    'competition_id' => $competition_id,
                    'product_id'     => $product_id,
                    'order_count'    => count( $order_ids ),
                    'meta_count'     => count( $meta_map ),
                ]
            );
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
            $query_args = [
                'limit'       => -1,
                'customer_id' => $uid,
            ];
            $statuses   = $this->get_relevant_order_statuses();
            if ( $statuses ) {
                $query_args['status'] = $statuses;
            }
            $orders = wc_get_orders( $query_args );
            $this->extract_registration_meta_from_orders( $orders, $product_id, $meta_map );
        }
        $this->log_debug(
            'Registration meta map populated from customer orders.',
            [
                'competition_id' => $competition_id,
                'product_id'     => $product_id,
                'meta_count'     => count( $meta_map ),
            ]
        );
        return $meta_map;
    }

    /**
     * Build the list of WooCommerce order statuses that should be considered valid registrations.
     *
     * @return string[]
     */
    private function get_relevant_order_statuses(): array {
        $statuses = [ 'pending', 'processing', 'completed', 'on-hold' ];

        if ( function_exists( 'wc_get_is_paid_statuses' ) ) {
            foreach ( (array) wc_get_is_paid_statuses() as $status ) {
                $statuses[] = is_string( $status ) ? $status : '';
            }
        }

        $normalized = [];
        foreach ( $statuses as $status ) {
            $status = trim( (string) $status );
            if ( $status === '' ) {
                continue;
            }
            $normalized[] = $status;
            if ( strpos( $status, 'wc-' ) === 0 ) {
                $normalized[] = substr( $status, 3 );
            } else {
                $normalized[] = 'wc-' . $status;
            }
        }

        $normalized = array_values( array_unique( array_filter( $normalized, static function ( $value ) {
            return $value !== '';
        } ) ) );

        return $normalized;
    }

    /**
     * @return int[]
     */
    private function get_order_ids_for_product( int $product_id ): array {
        global $wpdb;
        if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'prepare' ) || ! method_exists( $wpdb, 'get_col' ) ) {
            $this->log_debug( 'Cannot fetch order IDs: invalid $wpdb instance.' );
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
            $this->log_debug( 'No order IDs found for product.', [ 'product_id' => $product_id ] );
            return [];
        }
        $order_ids = array_map( 'intval', $results );
        $this->log_debug( 'Order IDs retrieved for product.', [ 'product_id' => $product_id, 'order_count' => count( $order_ids ) ] );
        return $order_ids;
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
                $weight          = trim( (string) $item->get_meta( 'دسته وزنی', true ) );
                $age             = trim( (string) $item->get_meta( 'رده سنی', true ) );
                $weight_term_raw = $item->get_meta( 'weight_class_term', true );
                $age_term_raw    = $item->get_meta( 'age_category_term', true );
                $weight_term_id  = is_numeric( $weight_term_raw ) ? (int) $weight_term_raw : 0;
                $age_term_id     = is_numeric( $age_term_raw ) ? (int) $age_term_raw : 0;

                if ( ! isset( $meta_map[ $uid ] ) ) {
                    $meta_map[ $uid ] = [];
                }

                if ( $weight !== '' && ( $meta_map[ $uid ]['weight_class'] ?? '' ) === '' ) {
                    $meta_map[ $uid ]['weight_class'] = $weight;
                }
                if ( $age !== '' && ( $meta_map[ $uid ]['age_category'] ?? '' ) === '' ) {
                    $meta_map[ $uid ]['age_category'] = $age;
                }
                if ( $weight_term_id > 0 && (int) ( $meta_map[ $uid ]['weight_class_term'] ?? 0 ) === 0 ) {
                    $meta_map[ $uid ]['weight_class_term'] = $weight_term_id;
                }
                if ( $age_term_id > 0 && (int) ( $meta_map[ $uid ]['age_category_term'] ?? 0 ) === 0 ) {
                    $meta_map[ $uid ]['age_category_term'] = $age_term_id;
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

    private function get_ranking_rows( array $competition_ids = [], array $weight_classes = [] ): array {
        global $wpdb;
        $where  = 'WHERE 1=1';
        $params = [];

        if ( $competition_ids ) {
            $competition_ids = array_map( 'intval', $competition_ids );
            $placeholders    = implode( ',', array_fill( 0, count( $competition_ids ), '%d' ) );
            $where          .= " AND competition_id IN ($placeholders)";
            $params         = array_merge( $params, $competition_ids );
        }

        if ( $weight_classes ) {
            $weight_classes = array_map( 'intval', $weight_classes );
            $placeholders   = implode( ',', array_fill( 0, count( $weight_classes ), '%d' ) );
            $where         .= " AND weight_class IN ($placeholders)";
            $params        = array_merge( $params, $weight_classes );
        }

        $expiry_days = (int) $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" );
        if ( $expiry_days ) {
            $where    .= ' AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)';
            $params[] = $expiry_days;
        }

        $sql = "SELECT user_id, SUM(points) AS pts FROM {$wpdb->prefix}crm_points $where GROUP BY user_id";
        if ( $params ) {
            $sql = $wpdb->prepare( $sql, ...$params );
        }

        return $wpdb->get_results( $sql );
    }

    /**
     * Prepare ranking entries enriched with user data and gender filtering.
     *
     * @param array<int,object>     $raw_rows Rows fetched from the database.
     * @param array<int,string|int> $gender_filter Normalized gender filters.
     *
     * @return array<int,array<string,mixed>>
     */
    private function build_competition_ranking_entries( array $raw_rows, array $gender_filter ): array {
        $entries = [];

        foreach ( $raw_rows as $row ) {
            $uid = (int) ( $row->user_id ?? 0 );
            if ( $uid <= 0 ) {
                continue;
            }

            $gender_slug = $this->normalize_slug( UserMeta::gender_slug( $uid ) );
            if ( $gender_filter && ! in_array( $gender_slug, $gender_filter, true ) ) {
                continue;
            }

            $user         = get_userdata( $uid );
            $display_name = is_object( $user ) && isset( $user->display_name ) ? (string) $user->display_name : '';
            $avatar       = get_user_meta( $uid, 'personal_photo', true ) ?: get_avatar_url( $uid );

            $entries[] = [
                'user_id'      => $uid,
                'display_name' => $display_name ?: $this->translate( 'کاربر' ) . ' ' . $uid,
                'points'       => (int) ( $row->pts ?? 0 ),
                'avatar'       => $avatar,
                'gender'       => $gender_slug,
            ];
        }

        return $entries;
    }

    /**
     * Sort ranking entries by the selected order.
     *
     * @param array<int,array<string,mixed>> $entries
     * @param string                         $order
     */
    private function sort_competition_entries( array &$entries, string $order ): void {
        $compare = static function ( array $a, array $b ) use ( $order ): int {
            switch ( $order ) {
                case 'points_asc':
                    return $a['points'] <=> $b['points'] ?: strnatcasecmp( (string) $a['display_name'], (string) $b['display_name'] );
                case 'name_asc':
                    return strnatcasecmp( (string) $a['display_name'], (string) $b['display_name'] ) ?: $b['points'] <=> $a['points'];
                case 'name_desc':
                    return strnatcasecmp( (string) $b['display_name'], (string) $a['display_name'] ) ?: $b['points'] <=> $a['points'];
                case 'points_desc':
                default:
                    return $b['points'] <=> $a['points'] ?: strnatcasecmp( (string) $a['display_name'], (string) $b['display_name'] );
            }
        };

        usort( $entries, $compare );
        $entries = array_values( $entries );
    }

    /**
     * AJAX endpoint for server-side rankings table.
     */
    public function ajax_ranking_table(): void {
        $this->verify_ajax_request();

        $competition_id = max( 0, intval( $_POST['competition_id'] ?? 0 ) );
        $weight_class   = max( 0, intval( $_POST['weight_class'] ?? 0 ) );
        $draw           = max( 0, intval( $_POST['draw'] ?? 0 ) );
        $start          = max( 0, intval( $_POST['start'] ?? 0 ) );
        $length         = max( -1, intval( $_POST['length'] ?? 20 ) );
        $search_term    = '';
        if ( isset( $_POST['search']['value'] ) && is_scalar( $_POST['search']['value'] ) ) {
            $search_term = (string) $_POST['search']['value'];
        }

        $order_request = $_POST['order'] ?? [];
        $order_by      = $this->parse_datatable_order( is_array( $order_request ) ? $order_request : [] );

        $rows = $this->get_rank_table_rows( $competition_id, $weight_class );
        $total_count = count( $rows );

        if ( $search_term !== '' ) {
            $rows = array_values(
                array_filter(
                    $rows,
                    function ( array $row ) use ( $search_term ): bool {
                        $needle = $this->safe_lower( trim( $search_term ) );
                        if ( $needle === '' ) {
                            return true;
                        }
                        $haystack = $this->safe_lower(
                            implode(
                                ' ',
                                [
                                    $this->strip_tags_safe( $row['user'] ?? '' ),
                                    $row['gender'] ?? '',
                                    $row['weights'] ?? '',
                                ]
                            )
                        );
                        return strpos( $haystack, $needle ) !== false;
                    }
                )
            );
        }

        $filtered_count = count( $rows );

        if ( $order_by ) {
            usort(
                $rows,
                function ( array $a, array $b ) use ( $order_by ): int {
                    foreach ( $order_by as $order ) {
                        $column = $order['column'];
                        $dir    = $order['dir'];
                        $av     = $a[ $column ] ?? '';
                        $bv     = $b[ $column ] ?? '';

                        if ( $column === 'user' ) {
                            $av = $this->strip_tags_safe( (string) $av );
                            $bv = $this->strip_tags_safe( (string) $bv );
                        }

                        if ( $av === $bv ) {
                            continue;
                        }

                        if ( is_numeric( $av ) && is_numeric( $bv ) ) {
                            $result = (float) $av <=> (float) $bv;
                            return $dir === 'asc' ? $result : -$result;
                        }

                        $result = strcasecmp( (string) $av, (string) $bv );
                        return $dir === 'asc' ? $result : -$result;
                    }

                    return 0;
                }
            );
        }

        if ( $length > -1 ) {
            $rows = array_slice( $rows, $start, $length );
        }

        foreach ( $rows as $idx => &$row ) {
            $row['position'] = $start + $idx + 1;
        }
        unset( $row );

        wp_send_json_success(
            [
                'draw'            => $draw,
                'recordsTotal'    => $total_count,
                'recordsFiltered' => $filtered_count,
                'data'            => array_values( $rows ),
            ]
        );
    }

    /**
     * Build ranking rows for DataTables response.
     *
     * @return array<int,array<string,mixed>>
     */
    private function get_rank_table_rows( int $competition_id, int $weight_class ): array {
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

        $results = $wpdb->get_results(
            "SELECT user_id, SUM(points) AS pts, GROUP_CONCAT(DISTINCT weight_class) AS weight_ids
             FROM {$wpdb->prefix}crm_points
             $where
             GROUP BY user_id",
            ARRAY_A
        );

        if ( ! $results ) {
            return [];
        }

        $user_cache    = [];
        $weight_lookup = $this->build_weight_lookup( $results );

        $rows = [];
        foreach ( $results as $row ) {
            $uid = isset( $row['user_id'] ) ? (int) $row['user_id'] : 0;
            if ( ! $uid ) {
                continue;
            }
            if ( ! isset( $user_cache[ $uid ] ) ) {
                $user_cache[ $uid ] = get_userdata( $uid );
            }
            $user = $user_cache[ $uid ];
            if ( ! $user instanceof WP_User ) {
                continue;
            }

            $display_name  = $user->display_name ?: $user->user_email;
            $avatar        = get_user_meta( $uid, 'personal_photo', true ) ?: get_avatar_url( $uid );
            $weights       = $this->format_weight_labels( (string) ( $row['weight_ids'] ?? '' ), $weight_lookup );
            $gender_label  = $this->get_gender_label( $uid );
            $points        = isset( $row['pts'] ) ? (int) $row['pts'] : 0;

            $rows[] = [
                'user_id' => $uid,
                'user'    => sprintf(
                    '<div class="crm-rank-user"><img src="%s" alt="" class="crm-rank-avatar"> %s</div>',
                    esc_url( $avatar ),
                    esc_html( $display_name )
                ),
                'gender'  => esc_html( $gender_label ),
                'weights' => esc_html( $weights ),
                'points'  => $points,
            ];
        }

        return $rows;
    }

    /**
     * Normalize ordering instructions from DataTables.
     *
     * @param array<int,array<string,mixed>> $orders
     * @return array<int,array{column:string,dir:string}>
     */
    private function parse_datatable_order( array $orders ): array {
        $map = [
            1 => 'user',
            2 => 'gender',
            3 => 'weights',
            4 => 'points',
        ];

        $parsed = [];
        foreach ( $orders as $order ) {
            $column_idx = isset( $order['column'] ) ? (int) $order['column'] : -1;
            $dir        = strtolower( (string) ( $order['dir'] ?? 'asc' ) ) === 'desc' ? 'desc' : 'asc';
            if ( isset( $map[ $column_idx ] ) ) {
                $parsed[] = [
                    'column' => $map[ $column_idx ],
                    'dir'    => $dir,
                ];
            }
        }

        if ( ! $parsed ) {
            $parsed[] = [ 'column' => 'points', 'dir' => 'desc' ];
        }

        return $parsed;
    }

    /**
     * @param array<int,array<string,mixed>> $results
     *
     * @return array<int,string>
     */
    private function build_weight_lookup( array $results ): array {
        $ids = [];
        foreach ( $results as $row ) {
            $parts = array_filter( array_map( 'intval', explode( ',', (string) ( $row['weight_ids'] ?? '' ) ) ) );
            foreach ( $parts as $id ) {
                $ids[ $id ] = $id;
            }
        }
        if ( ! $ids ) {
            return [];
        }
        $terms = get_terms(
            [
                'taxonomy'   => 'age_category',
                'include'    => array_values( $ids ),
                'hide_empty' => false,
            ]
        );
        $lookup      = [];
        $parent_map  = [];
        $parents_to_fetch = [];
        foreach ( $terms as $term ) {
            if ( $term instanceof WP_Term ) {
                $term_id                = (int) $term->term_id;
                $parent_id              = (int) $term->parent;
                $lookup[ $term_id ]     = (string) $term->name;
                $parent_map[ $term_id ] = $parent_id;
                if ( $parent_id ) {
                    $parents_to_fetch[ $parent_id ] = $parent_id;
                }
            }
        }

        if ( $parents_to_fetch ) {
            $parents = get_terms(
                [
                    'taxonomy'   => 'age_category',
                    'include'    => array_values( $parents_to_fetch ),
                    'hide_empty' => false,
                ]
            );
            foreach ( $parents as $parent ) {
                if ( $parent instanceof WP_Term ) {
                    $lookup[ (int) $parent->term_id ] = (string) $parent->name;
                }
            }
        }

        $labels = [];
        foreach ( $lookup as $id => $name ) {
            $parent_id = $parent_map[ $id ] ?? 0;
            if ( $parent_id && isset( $lookup[ $parent_id ] ) ) {
                $labels[ $id ] = $lookup[ $parent_id ] . ' - ' . $name;
            } else {
                $labels[ $id ] = $name;
            }
        }

        return $labels;
    }

    private function format_weight_labels( string $csv, array $lookup ): string {
        $ids = array_filter( array_map( 'intval', explode( ',', $csv ) ) );
        if ( ! $ids ) {
            return '—';
        }
        $labels = [];
        foreach ( array_unique( $ids ) as $id ) {
            if ( isset( $lookup[ $id ] ) ) {
                $labels[] = $lookup[ $id ];
            }
        }
        return $labels ? implode( '، ', $labels ) : '—';
    }

    private function get_gender_label( int $user_id ): string {
        $slug = UserMeta::gender_slug( $user_id );
        if ( $slug !== '' && isset( $this->gender_label_cache[ $slug ] ) ) {
            return $this->gender_label_cache[ $slug ];
        }
        return $slug !== '' ? $slug : '—';
    }

    private function safe_lower( string $value ): string {
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $value, 'UTF-8' );
        }

        return strtolower( $value );
    }

    private function strip_tags_safe( string $value ): string {
        if ( function_exists( 'wp_strip_all_tags' ) ) {
            return wp_strip_all_tags( $value );
        }

        return strip_tags( $value );
    }

    /**
     * Display rankings grouped by gender, age category, and weight class.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     */
    public function rankings_overview_shortcode( $atts = [] ): string {
        $atts = shortcode_atts(
            [
                'competition' => 0,
                'gender'      => '',
                'age'         => '',
                'weight'      => '',
                'limit'       => 0,
                'show_style'  => 'yes',
            ],
            $atts,
            'crm_rankings_overview'
        );

        $competition_id = intval( $atts['competition'] );
        $gender_filter  = $this->parse_filter_list( $atts['gender'] );
        $age_filter     = $this->parse_filter_list( $atts['age'] );
        $weight_filter  = $this->parse_filter_list( $atts['weight'] );
        $limit          = max( 0, intval( $atts['limit'] ) );

        $rows = $this->get_weight_class_rankings( $competition_id );
        if ( ! $rows ) {
            return '<p>' . esc_html( $this->translate( 'امتیازی ثبت نشده است.' ) ) . '</p>';
        }

        $groups = [];

        foreach ( $rows as $row ) {
            $weight_id = (int) ( $row->weight_class ?? 0 );
            $user_id   = (int) ( $row->user_id ?? 0 );
            if ( $weight_id <= 0 || $user_id <= 0 ) {
                continue;
            }

            $weight_term = get_term( $weight_id, 'age_category' );
            if ( ! $weight_term || is_wp_error( $weight_term ) ) {
                continue;
            }

            $age_term = null;
            if ( $weight_term->parent ) {
                $parent = get_term( (int) $weight_term->parent, 'age_category' );
                if ( $parent && ! is_wp_error( $parent ) ) {
                    $age_term = $parent;
                }
            }

            if ( $age_filter && ! $this->term_matches_filter( $age_term, $age_filter ) ) {
                continue;
            }

            if ( $weight_filter && ! $this->term_matches_filter( $weight_term, $weight_filter ) ) {
                continue;
            }

            $gender_slug = $this->normalize_slug( UserMeta::gender_slug( $user_id ) );
            if ( $gender_filter && ! in_array( $gender_slug, $gender_filter, true ) ) {
                continue;
            }

            $gender_key = $gender_slug ?: 'unknown';
            if ( ! isset( $groups[ $gender_key ] ) ) {
                $groups[ $gender_key ] = [
                    'label' => $this->gender_label( $gender_key ),
                    'ages'  => [],
                ];
            }

            $age_key   = $age_term instanceof WP_Term ? (string) $age_term->term_id : 'unassigned';
            $age_label = $age_term instanceof WP_Term ? (string) $age_term->name : $this->translate( 'رده سنی نامشخص' );
            if ( ! isset( $groups[ $gender_key ]['ages'][ $age_key ] ) ) {
                $groups[ $gender_key ]['ages'][ $age_key ] = [
                    'label'   => $age_label,
                    'term'    => $age_term,
                    'weights' => [],
                ];
            }

            $weight_key   = (string) $weight_term->term_id;
            $weight_label = (string) $weight_term->name;
            if ( ! isset( $groups[ $gender_key ]['ages'][ $age_key ]['weights'][ $weight_key ] ) ) {
                $groups[ $gender_key ]['ages'][ $age_key ]['weights'][ $weight_key ] = [
                    'label' => $weight_label,
                    'rows'  => [],
                ];
            }

            $groups[ $gender_key ]['ages'][ $age_key ]['weights'][ $weight_key ]['rows'][] = [
                'user_id' => $user_id,
                'points'  => (int) ( $row->pts ?? 0 ),
            ];
        }

        if ( ! $groups ) {
            return '<p>' . esc_html( $this->translate( 'امتیازی ثبت نشده است.' ) ) . '</p>';
        }

        foreach ( $groups as &$gender_group ) {
            foreach ( $gender_group['ages'] as &$age_group ) {
                foreach ( $age_group['weights'] as &$weight_group ) {
                    usort(
                        $weight_group['rows'],
                        static fn( array $a, array $b ): int => $b['points'] <=> $a['points'] ?: $a['user_id'] <=> $b['user_id']
                    );
                    if ( $limit > 0 && count( $weight_group['rows'] ) > $limit ) {
                        $weight_group['rows'] = array_slice( $weight_group['rows'], 0, $limit );
                    }
                }
                unset( $weight_group );
                uasort(
                    $age_group['weights'],
                    static fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] )
                );
            }
            unset( $age_group );
            uasort(
                $gender_group['ages'],
                static fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] )
            );
        }
        unset( $gender_group );

        uasort(
            $groups,
            static fn( array $a, array $b ): int => strnatcasecmp( $a['label'], $b['label'] )
        );

        ob_start();
        if ( $this->is_truthy( $atts['show_style'] ) ) {
            $this->maybe_print_overview_style();
        }

        echo '<div class="crm-rankings-overview" data-competition="' . esc_attr( (string) $competition_id ) . '">';
        foreach ( $groups as $gender_slug => $gender_group ) {
            echo '<section class="crm-rankings-overview__gender" data-gender="' . esc_attr( $gender_slug ) . '">';
            echo '<header class="crm-rankings-overview__gender-header">';
            echo '<h2 class="crm-rankings-overview__gender-title">' . esc_html( $gender_group['label'] ) . '</h2>';
            echo '</header>';

            foreach ( $gender_group['ages'] as $age_key => $age_group ) {
                echo '<section class="crm-rankings-overview__age" data-age="' . esc_attr( (string) $age_key ) . '">';
                echo '<h3 class="crm-rankings-overview__age-title">' . esc_html( $age_group['label'] ) . '</h3>';

                foreach ( $age_group['weights'] as $weight_key => $weight_group ) {
                    echo '<div class="crm-rankings-overview__weight" data-weight="' . esc_attr( (string) $weight_key ) . '">';
                    echo '<h4 class="crm-rankings-overview__weight-title">' . esc_html( $weight_group['label'] ) . '</h4>';
                    echo '<table class="crm-rankings-overview__table">';
                    echo '<thead><tr><th scope="col">#</th><th scope="col">' . esc_html( $this->translate( 'ورزشکار' ) ) . '</th><th scope="col">' . esc_html( $this->translate( 'امتیاز' ) ) . '</th></tr></thead>';
                    echo '<tbody>';

                    $position = 1;
                    foreach ( $weight_group['rows'] as $entry ) {
                        $uid  = (int) $entry['user_id'];
                        $user = get_userdata( $uid );
                        $name = '—';
                        if ( is_object( $user ) && isset( $user->display_name ) && $user->display_name !== '' ) {
                            $name = (string) $user->display_name;
                        }
                        $img  = get_user_meta( $uid, 'personal_photo', true ) ?: get_avatar_url( $uid );

                        echo '<tr class="crm-rankings-overview__row">';
                        echo '<td class="crm-rankings-overview__cell crm-rankings-overview__cell--position">' . esc_html( (string) $position ) . '</td>';
                        echo '<td class="crm-rankings-overview__cell crm-rankings-overview__cell--athlete">';
                        echo '<span class="crm-rankings-overview__avatar"><img src="' . esc_url( (string) $img ) . '" alt="" loading="lazy"></span>';
                        echo '<span class="crm-rankings-overview__name">' . esc_html( $name ) . '</span>';
                        echo '</td>';
                        echo '<td class="crm-rankings-overview__cell crm-rankings-overview__cell--points">' . esc_html( (string) $entry['points'] ) . '</td>';
                        echo '</tr>';
                        ++$position;
                    }

                    echo '</tbody></table>';
                    echo '</div>';
                }
                echo '</section>';
            }
            echo '</section>';
        }
        echo '</div>';

        return ob_get_clean();
    }

    public function competition_rankings_shortcode( $atts = [] ): string {
        $atts = shortcode_atts(
            [
                'id'          => 0,
                'competition' => 0,
                'weight'      => '',
                'gender'      => '',
                'order'       => 'points_desc',
            ],
            $atts,
            'crm_competition_rankings'
        );

        $competition_filter = $this->parse_int_list( $atts['competition'] ?: ( $atts['id'] ?: ( $_GET['competition_id'] ?? '' ) ) );
        $weight_filter      = $this->parse_int_list( $atts['weight'] ?: ( $_GET['weight_class'] ?? '' ) );
        $gender_filter      = $this->parse_filter_list( $atts['gender'] );
        $order              = $this->sanitize_ranking_order( $atts['order'] );

        if ( ! $competition_filter ) {
            return '<p>مسابقه نامشخص است.</p>';
        }

        $raw_rows = $this->get_ranking_rows( $competition_filter, $weight_filter );
        $rows     = $this->build_competition_ranking_entries( $raw_rows, $gender_filter );

        if ( ! $rows ) {
            return '<p>امتیازی ثبت نشده است.</p>';
        }

        $this->sort_competition_entries( $rows, $order );

        ob_start();
        ?>
        <table class="crm-rank-table striped">
            <thead><tr><th>#</th><th>کاربر</th><th>امتیاز</th></tr></thead><tbody>
            <?php foreach ( $rows as $index => $r ) : ?>
                <tr>
                    <td><?php echo intval( $index + 1 ); ?></td>
                    <td><img src="<?php echo esc_url( $r['avatar'] ); ?>" style="width:30px;height:30px;border-radius:50%;vertical-align:middle" alt=""> <?php echo esc_html( $r['display_name'] ); ?></td>
                    <td><?php echo intval( $r['points'] ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }

    /**
     * Retrieve ranking rows grouped by weight class.
     *
     * @return array<int,object>
     */
    private function get_weight_class_rankings( int $competition_id = 0 ): array {
        global $wpdb;

        $where = 'WHERE weight_class > 0';
        if ( $competition_id ) {
            $where .= $wpdb->prepare( ' AND competition_id=%d', $competition_id );
        }

        $expiry_days = (int) $wpdb->get_var( "SELECT opt_val FROM {$wpdb->prefix}crm_settings WHERE opt_key='points_expiry_days' LIMIT 1" );
        if ( $expiry_days ) {
            $where .= $wpdb->prepare( ' AND assigned_date >= DATE_SUB(NOW(), INTERVAL %d DAY)', $expiry_days );
        }

        $sql = "SELECT weight_class, user_id, SUM(points) AS pts FROM {$wpdb->prefix}crm_points $where GROUP BY weight_class, user_id ORDER BY weight_class ASC, pts DESC";

        return $wpdb->get_results( $sql );
    }

    /**
     * Determine if a term matches any value from the provided filter list.
     *
     * @param array<int,int|string> $filters
     */
    private function term_matches_filter( ?WP_Term $term, array $filters ): bool {
        if ( ! $term ) {
            return in_array( 'unassigned', $filters, true );
        }

        foreach ( $filters as $filter ) {
            if ( is_int( $filter ) && (int) $term->term_id === $filter ) {
                return true;
            }
            if ( is_string( $filter ) ) {
                $slug = $this->normalize_slug( (string) $term->slug );
                if ( $slug === $filter ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse a CSV list attribute into normalized identifiers.
     *
     * @return array<int,int|string>
     */
    private function parse_filter_list( $value ): array {
        if ( is_array( $value ) ) {
            $value = implode( ',', $value );
        }

        $value = trim( (string) $value );
        if ( $value === '' ) {
            return [];
        }

        $parts   = array_filter( array_map( 'trim', explode( ',', $value ) ), 'strlen' );
        $filters = [];
        foreach ( $parts as $part ) {
            if ( ctype_digit( $part ) ) {
                $filters[] = (int) $part;
                continue;
            }

            $filters[] = $this->normalize_slug( $part );
        }

        return array_values( array_unique( $filters, SORT_REGULAR ) );
    }

    /**
     * Parse a CSV list and keep only integer values.
     *
     * @param mixed $value
     *
     * @return array<int,int>
     */
    private function parse_int_list( $value ): array {
        $filters = $this->parse_filter_list( $value );

        return array_values( array_filter( $filters, 'is_int' ) );
    }

    /**
     * Sanitize the requested ranking order.
     */
    private function sanitize_ranking_order( $value ): string {
        $allowed = [ 'points_desc', 'points_asc', 'name_asc', 'name_desc' ];
        $value   = $this->normalize_slug( (string) $value );

        return in_array( $value, $allowed, true ) ? $value : 'points_desc';
    }

    /**
     * Normalize a slug value.
     */
    private function normalize_slug( string $value ): string {
        $value = strtolower( trim( $value ) );
        if ( $value === '' ) {
            return '';
        }

        $value = preg_replace( '/[^\p{L}0-9_-]+/u', '-', $value );
        return trim( (string) $value, '-' );
    }

    /**
     * Fetch a label for the provided gender slug.
     */
    private function gender_label( string $slug ): string {
        $slug = $slug ?: 'unknown';
        if ( isset( $this->gender_label_cache[ $slug ] ) ) {
            return $this->gender_label_cache[ $slug ];
        }

        $label = '';
        $term  = get_term_by( 'slug', $slug, 'gender' );
        if ( $term && ! is_wp_error( $term ) ) {
            $label = (string) $term->name;
        }

        if ( $label === '' ) {
            switch ( $slug ) {
                case 'men':
                    $label = 'مردان';
                    break;
                case 'women':
                    $label = 'زنان';
                    break;
                case 'unknown':
                    $label = $this->translate( 'نامشخص' );
                    break;
                default:
                    $label = ucfirst( $slug );
                    break;
            }
        }

        return $this->gender_label_cache[ $slug ] = $label;
    }

    private function maybe_print_overview_style(): void {
        if ( self::$overview_style_printed ) {
            return;
        }
        self::$overview_style_printed = true;

        echo '<style class="crm-rankings-overview-style">'
            . '.crm-rankings-overview{display:grid;gap:2rem;margin:2rem 0;}'
            . '.crm-rankings-overview__gender{background:#fff;border:1px solid rgba(0,0,0,.08);border-radius:12px;padding:1.5rem;box-shadow:0 12px 24px rgba(15,23,42,.06);}'
            . '.crm-rankings-overview__gender-title{margin:0 0 1rem;font-size:1.5rem;font-weight:700;}'
            . '.crm-rankings-overview__age{margin-bottom:1.5rem;}'
            . '.crm-rankings-overview__age:last-child{margin-bottom:0;}'
            . '.crm-rankings-overview__age-title{margin:0 0 1rem;font-size:1.125rem;font-weight:600;}'
            . '.crm-rankings-overview__weight{margin-bottom:1rem;border:1px solid rgba(15,23,42,.08);border-radius:10px;padding:1rem;background:rgba(248,250,252,.9);}'
            . '.crm-rankings-overview__weight:last-child{margin-bottom:0;}'
            . '.crm-rankings-overview__weight-title{margin:0 0 .75rem;font-size:1rem;font-weight:600;}'
            . '.crm-rankings-overview__table{width:100%;border-collapse:collapse;font-size:.9375rem;}'
            . '.crm-rankings-overview__table thead{background:rgba(15,23,42,.05);}'
            . '.crm-rankings-overview__table th,.crm-rankings-overview__table td{padding:.6rem .75rem;text-align:start;border-bottom:1px solid rgba(15,23,42,.08);}'
            . '.crm-rankings-overview__row:last-child td{border-bottom:none;}'
            . '.crm-rankings-overview__cell--position{width:3rem;font-weight:600;text-align:center;}'
            . '.crm-rankings-overview__cell--athlete{display:flex;align-items:center;gap:.75rem;}'
            . '.crm-rankings-overview__avatar img{width:42px;height:42px;border-radius:50%;object-fit:cover;box-shadow:0 4px 10px rgba(15,23,42,.12);}'
            . '.crm-rankings-overview__cell--points{font-weight:600;text-align:center;}'
            . '@media (min-width:768px){.crm-rankings-overview{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));}}'
            . '</style>';
    }

    private function is_truthy( $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        $value = strtolower( trim( (string) $value ) );
        if ( $value === '' ) {
            return false;
        }

        return in_array( $value, [ '1', 'true', 'yes', 'on' ], true );
    }

    private function translate( string $text ): string {
        if ( function_exists( '__' ) ) {
            return __( $text, 'imao-custom-plugin' );
        }

        return $text;
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
        echo '<table id="crm-ranking-table" class="widefat striped" style="width:100%">';
        echo '<thead><tr>'
             . '<th>#</th>'
             . '<th>کاربر</th>'
             . '<th>جنسیت</th>'
             . '<th>دسته‌های وزنی</th>'
             . '<th>امتیاز</th>'
             . '</tr></thead>';
        echo '<tbody><tr><td colspan="5">در حال بارگذاری…</td></tr></tbody>';
        echo '</table>';
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
