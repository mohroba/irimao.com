<?php

namespace IMAOCustom\Services;

use IMAOCustom\Forms\CityRepresentativeForm;
use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\RepresentativeManager;
use WP_User;

class ProvinceRepresentatives {
    private const ADMIN_SLUG      = 'imao-province-reps';
    private const ACCOUNT_SLUG    = 'city-representatives';
    private const ROLE_PROVINCE   = 'province_rep';
    private const ROLE_CITY       = 'city_rep';

    private RepresentativeManager $manager;

    public function __construct() {
        $this->manager = new RepresentativeManager();
    }

    public function register(): void {
        register_activation_hook( IMAO_PLUGIN_FILE, [ $this, 'activate' ] );
        add_action( 'plugins_loaded', [ $this, 'maybe_install' ] );
        add_action( 'init', [ $this, 'register_roles' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'init', [ $this, 'add_account_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_account_menu' ] );
        add_action( 'woocommerce_account_' . self::ACCOUNT_SLUG . '_endpoint', [ $this, 'render_account_endpoint' ] );
    }

    public function activate(): void {
        $this->register_roles();
        $this->manager->install();
        $this->add_account_endpoint();
        flush_rewrite_rules();
    }

    public function maybe_install(): void {
        $this->manager->install();
    }

    public function register_roles(): void {
        if ( ! get_role( self::ROLE_PROVINCE ) ) {
            add_role( self::ROLE_PROVINCE, 'نماینده استان', [ 'read' => true, 'assign_city_representatives' => true ] );
        }
        if ( ! get_role( self::ROLE_CITY ) ) {
            add_role( self::ROLE_CITY, 'نماینده شهرستان', [ 'read' => true ] );
        }
    }

    public function add_admin_menu(): void {
        add_users_page( 'نمایندگان استان ها', 'نمایندگان استان ها', 'manage_options', self::ADMIN_SLUG, [ $this, 'render_admin_page' ] );
    }

    public function enqueue_admin_assets( string $hook ): void {
        $page = $_GET['page'] ?? '';
        if ( $page !== self::ADMIN_SLUG ) {
            return;
        }
        $base = plugin_dir_url( dirname( __DIR__, 2 ) ) . 'assets/';
        wp_enqueue_style( 'imao-datatables', $base . 'css/jquery.dataTables.min.css' );
        wp_enqueue_style( 'imao-datatables-buttons', 'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css', [], '2.4.2' );
        wp_enqueue_style( 'imao-select2', $base . 'css/select2.min.css' );
        wp_enqueue_style( 'imao-province-reps', $base . 'css/province-reps-admin.css' );

        wp_enqueue_script( 'imao-datatables', $base . 'js/jquery.dataTables.min.js', [ 'jquery' ], null, true );
        wp_enqueue_script( 'imao-datatables-buttons', 'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js', [ 'imao-datatables' ], '2.4.2', true );
        wp_enqueue_script( 'imao-jszip', 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', [], '3.10.1', true );
        wp_enqueue_script( 'imao-datatables-excel', 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js', [ 'imao-datatables-buttons', 'imao-jszip' ], '2.4.2', true );
        wp_enqueue_script( 'imao-select2', $base . 'js/select2.min.js', [ 'jquery' ], null, true );
        wp_enqueue_script( 'imao-province-reps', $base . 'js/province-reps-admin.js', [ 'jquery', 'imao-datatables', 'imao-datatables-excel', 'imao-select2' ], null, true );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }

        $tab = sanitize_text_field( $_GET['tab'] ?? 'assign' );
        $notice = '';
        if ( $tab === 'assign' && isset( $_POST['imao_assign_province'] ) ) {
            check_admin_referer( 'imao_assign_province' );
            $user_id  = isset( $_POST['province_user'] ) ? (int) $_POST['province_user'] : 0;
            $province = sanitize_text_field( $_POST['province_code'] ?? '' );
            $result   = $this->manager->assign_province_representative( $user_id, $province, get_current_user_id() );
            if ( $result['ok'] ?? false ) {
                $notice = '<div class="updated"><p>نماینده استان ذخیره شد.</p></div>';
            } else {
                $msg    = esc_html( $result['message'] ?? 'خطا در ذخیره نماینده.' );
                $notice = '<div class="notice notice-error"><p>' . $msg . '</p></div>';
            }
        }

        echo '<div class="wrap"><h1 class="wp-heading-inline">نمایندگان استان ها</h1><hr>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab ' . ( $tab === 'assign' ? 'nav-tab-active' : '' ) . '" href="?page=' . self::ADMIN_SLUG . '&tab=assign">ثبت نماینده استان</a>';
        echo '<a class="nav-tab ' . ( $tab === 'list' ? 'nav-tab-active' : '' ) . '" href="?page=' . self::ADMIN_SLUG . '&tab=list">فهرست نمایندگان استان</a>';
        echo '<a class="nav-tab ' . ( $tab === 'cities' ? 'nav-tab-active' : '' ) . '" href="?page=' . self::ADMIN_SLUG . '&tab=cities">نمایندگان شهرستان</a>';
        echo '</h2>';
        echo $notice;

        if ( $tab === 'list' ) {
            $this->render_province_table();
        } elseif ( $tab === 'cities' ) {
            $this->render_city_table();
        } else {
            $this->render_assign_form();
        }
        echo '</div>';
    }

    private function render_assign_form(): void {
        $provinces = CityMap::get_provinces();
        $users     = get_users( [ 'orderby' => 'display_name' ] );
        ?>
        <form method="post" class="imao-rep-form">
            <?php wp_nonce_field( 'imao_assign_province' ); ?>
            <table class="form-table">
                <tr>
                    <th>کاربر</th>
                    <td>
                        <select name="province_user" class="crm-select2" required style="width:320px;">
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $users as $user ) : ?>
                                <option value="<?= esc_attr( (string) $user->ID ); ?>"><?= esc_html( $user->display_name . ' (#' . $user->ID . ')' ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>استان</th>
                    <td>
                        <select name="province_code" class="crm-select2" required style="width:320px;">
                            <option value="">— استان —</option>
                            <?php foreach ( $provinces as $code => $name ) : ?>
                                <option value="<?= esc_attr( $code ); ?>"><?= esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            </table>
            <p><button type="submit" class="button button-primary" name="imao_assign_province" value="1">ذخیره نماینده</button></p>
        </form>
        <?php
    }

    private function render_province_table(): void {
        $assignments = $this->manager->get_province_assignments();
        $provinces   = CityMap::get_provinces();
        ?>
        <table class="widefat striped imao-reps-table">
            <thead>
                <tr>
                    <th>استان</th>
                    <th>کاربر</th>
                    <th>وضعیت</th>
                    <th>تاریخ انتصاب</th>
                    <th>توسط</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $row ) :
                    $user       = get_userdata( (int) $row->user_id );
                    $assignedBy = get_userdata( (int) $row->assigned_by );
                    ?>
                    <tr>
                        <td><?= esc_html( $provinces[ $row->province_code ] ?? $row->province_code ); ?></td>
                        <td><?= $user instanceof WP_User ? esc_html( $user->display_name ) : '—'; ?></td>
                        <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                        <td><?= esc_html( $row->assigned_at ); ?></td>
                        <td><?= $assignedBy instanceof WP_User ? esc_html( $assignedBy->display_name ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    private function render_city_table(): void {
        $assignments = $this->manager->get_city_assignments();
        $provinces   = CityMap::get_provinces();
        ?>
        <table class="widefat striped imao-reps-table">
            <thead>
                <tr>
                    <th>استان</th>
                    <th>شهرستان</th>
                    <th>کاربر</th>
                    <th>وضعیت</th>
                    <th>تاریخ انتصاب</th>
                    <th>توسط</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $row ) :
                    $user       = get_userdata( (int) $row->user_id );
                    $assignedBy = get_userdata( (int) $row->assigned_by );
                    ?>
                    <tr>
                        <td><?= esc_html( $provinces[ $row->province_code ] ?? $row->province_code ); ?></td>
                        <td><?= esc_html( $row->city_name ); ?></td>
                        <td><?= $user instanceof WP_User ? esc_html( $user->display_name ) : '—'; ?></td>
                        <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                        <td><?= esc_html( $row->assigned_at ); ?></td>
                        <td><?= $assignedBy instanceof WP_User ? esc_html( $assignedBy->display_name ) : '—'; ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    public function add_account_endpoint(): void {
        add_rewrite_endpoint( self::ACCOUNT_SLUG, EP_ROOT | EP_PAGES );
    }

    public function add_account_menu( array $items ): array {
        if ( ! $this->current_user_is_province_rep() ) {
            return $items;
        }
        $items = array_slice( $items, 0, 1, true ) + [ self::ACCOUNT_SLUG => 'نمایندگان شهرستان' ] + array_slice( $items, 1, null, true );
        return $items;
    }

    public function render_account_endpoint(): void {
        if ( ! $this->current_user_is_province_rep() ) {
            echo '<div class="woocommerce-error">شما دسترسی لازم برای این بخش را ندارید.</div>';
            return;
        }
        $province = $this->manager->get_active_province_for_user( get_current_user_id() );
        if ( ! $province ) {
            echo '<div class="woocommerce-info">استانی برای شما ثبت نشده است.</div>';
            return;
        }

        $form = new CityRepresentativeForm( $this->manager, (string) $province['province_code'] );
        echo $form->render();
    }

    private function current_user_is_province_rep(): bool {
        $user = wp_get_current_user();
        return $user instanceof WP_User && in_array( self::ROLE_PROVINCE, (array) $user->roles, true );
    }
}
