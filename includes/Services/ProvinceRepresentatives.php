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
        add_action( 'wp_ajax_imao_lookup_user_national', [ $this, 'ajax_lookup_user_national' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );
        add_action( 'init', [ $this, 'add_account_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_account_menu' ] );
        add_action( 'woocommerce_account_' . self::ACCOUNT_SLUG . '_endpoint', [ $this, 'render_account_endpoint' ] );
        add_shortcode( 'crm_city_representatives', [ $this, 'city_representatives_shortcode' ] );
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
        if ( ! get_role( RepresentativeManager::ROLE_PROVINCE ) ) {
            add_role( RepresentativeManager::ROLE_PROVINCE, 'نماینده استان', [ 'read' => true, 'assign_city_representatives' => true ] );
        }
        if ( ! get_role( RepresentativeManager::ROLE_CITY ) ) {
            add_role( RepresentativeManager::ROLE_CITY, 'نماینده شهرستان', [ 'read' => true ] );
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
        $base = plugin_dir_url( IMAO_PLUGIN_FILE ) . 'assets/';
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
        $provinces = CityMap::get_provinces();
        $cities    = [];
        foreach ( $provinces as $code => $_name ) {
            $cities[ $code ] = CityMap::get_cities( (string) $code );
        }
        wp_localize_script(
            'imao-province-reps',
            'IMAOREPS',
            [
                'cityMap'   => $cities,
                'provinces' => $provinces,
                'genders'   => RepresentativeManager::gender_labels(),
            ]
        );
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
            $gender   = sanitize_text_field( $_POST['province_gender'] ?? '' );
            $result   = $this->manager->assign_province_representative( $user_id, $province, get_current_user_id(), $gender );
            if ( $result['ok'] ?? false ) {
                $notice = '<div class="updated"><p>نماینده استان ذخیره شد.</p></div>';
            } else {
                $msg    = esc_html( $result['message'] ?? 'خطا در ذخیره نماینده.' );
                $notice = '<div class="notice notice-error"><p>' . $msg . '</p></div>';
            }
        }

        if ( $tab === 'list' && isset( $_POST['imao_province_action'] ) ) {
            check_admin_referer( 'imao_province_action' );
            $notice = $this->handle_province_actions();
        }

        if ( $tab === 'list' && isset( $_POST['imao_edit_province'] ) ) {
            check_admin_referer( 'imao_province_edit' );
            $notice = $this->handle_province_edit();
        }

        if ( $tab === 'cities' && isset( $_POST['imao_city_action'] ) ) {
            check_admin_referer( 'imao_city_action' );
            $notice = $this->handle_city_actions();
        }

        if ( $tab === 'cities' && isset( $_POST['imao_edit_city'] ) ) {
            check_admin_referer( 'imao_city_edit' );
            $notice = $this->handle_city_edit();
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
        $genders   = \IMAOCustom\Helpers\RepresentativeManager::gender_labels();
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
                <tr>
                    <th>جنسیت نماینده</th>
                    <td>
                        <select name="province_gender" class="crm-select2" required style="width:320px;">
                            <option value="">— انتخاب کنید —</option>
                            <?php foreach ( $genders as $g_key => $g_label ) : ?>
                                <option value="<?= esc_attr( $g_key ); ?>"><?= esc_html( $g_label ); ?></option>
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
        $users       = get_users( [ 'orderby' => 'display_name' ] );
        $genders     = \IMAOCustom\Helpers\RepresentativeManager::gender_labels();
        ?>
        <form method="post">
            <?php wp_nonce_field( 'imao_province_action' ); ?>
            <table class="widefat striped imao-reps-table" id="imao-province-table">
            <thead>
                <tr>
                    <th>استان</th>
                    <th>جنسیت</th>
                    <th>کاربر</th>
                    <th>وضعیت</th>
                    <th>تاریخ انتصاب</th>
                    <th>تاریخ لغو</th>
                    <th>توسط</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $row ) :
                    $user       = get_userdata( (int) $row->user_id );
                    $assignedBy = get_userdata( (int) $row->assigned_by );
                    $basic_link = admin_url( 'users.php?page=imao-basic-info&user_id=' . (int) $row->user_id );
                    $login_link = wp_nonce_url( admin_url( 'admin-post.php?action=imao_login_as&user_id=' . (int) $row->user_id ), 'imao_login_as_' . (int) $row->user_id );
                    ?>
                    <tr>
                        <td><?= esc_html( $provinces[ $row->province_code ] ?? $row->province_code ); ?></td>
                        <td><?= esc_html( $genders[ $row->gender ] ?? '—' ); ?></td>
                        <td>
                            <?php if ( $user instanceof WP_User ) : ?>
                                <a href="<?= esc_url( $basic_link ); ?>"><?= esc_html( $user->display_name ); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                        <td><?= esc_html( $row->assigned_at ); ?></td>
                        <td><?= esc_html( $row->deactivated_at ?: '—' ); ?></td>
                        <td><?= $assignedBy instanceof WP_User ? esc_html( $assignedBy->display_name ) : '—'; ?></td>
                        <td class="imao-actions">
                            <input type="hidden" name="assignment[<?= esc_attr( (string) $row->id ); ?>][id]" value="<?= esc_attr( (string) $row->id ); ?>">
                            <?php if ( $row->status === 'active' ) : ?>
                                <button class="button deactivate-btn" name="assignment[<?= esc_attr( (string) $row->id ); ?>][act]" value="deactivate">لغو</button>
                            <?php endif; ?>
                            <button class="button delete-btn" name="assignment[<?= esc_attr( (string) $row->id ); ?>][act]" value="delete">حذف</button>
                            <button type="button" class="button edit-btn" data-type="province"
                                    data-id="<?= esc_attr( (string) $row->id ); ?>"
                                    data-user="<?= esc_attr( (string) $row->user_id ); ?>"
                                    data-province="<?= esc_attr( $row->province_code ); ?>"
                                    data-gender="<?= esc_attr( $row->gender ); ?>"
                                    data-status="<?= esc_attr( $row->status ); ?>">
                                ویرایش
                            </button>
                            <?php if ( $user instanceof WP_User ) : ?>
                                <a class="button" href="<?= esc_url( $login_link ); ?>">ورود</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
            <input type="hidden" name="imao_province_action" value="1">
        </form>
        <div id="province-edit-panel" class="imao-edit-panel" style="display:none;">
            <h3>ویرایش نماینده استان</h3>
            <form method="post">
                <?php wp_nonce_field( 'imao_province_edit' ); ?>
                <input type="hidden" name="edit_province[id]" id="edit_province_id">
                <table class="form-table">
                    <tr>
                        <th>کاربر</th>
                        <td>
                            <select name="edit_province[user_id]" id="edit_province_user" class="crm-select2" style="width:320px;" required>
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
                            <select name="edit_province[province_code]" id="edit_province_code" class="crm-select2" style="width:320px;" required>
                                <option value="">— استان —</option>
                                <?php foreach ( $provinces as $code => $name ) : ?>
                                    <option value="<?= esc_attr( $code ); ?>"><?= esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>جنسیت</th>
                        <td>
                            <select name="edit_province[gender]" id="edit_province_gender" class="crm-select2" style="width:320px;" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ( $genders as $g_key => $g_label ) : ?>
                                    <option value="<?= esc_attr( $g_key ); ?>"><?= esc_html( $g_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>وضعیت</th>
                        <td>
                            <select name="edit_province[status]" id="edit_province_status" class="crm-select2" style="width:320px;" required>
                                <option value="active">فعال</option>
                                <option value="inactive">غیرفعال</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>
                <input type="hidden" name="imao_edit_province" value="1">
            </form>
        </div>
        <?php
    }

    private function render_city_table(): void {
        $assignments = $this->manager->get_city_assignments();
        $provinces   = CityMap::get_provinces();
        $genders     = \IMAOCustom\Helpers\RepresentativeManager::gender_labels();
        $users       = get_users( [ 'orderby' => 'display_name' ] );
        ?>
        <form method="post">
            <?php wp_nonce_field( 'imao_city_action' ); ?>
            <table class="widefat striped imao-reps-table" id="imao-city-table">
            <thead>
                <tr>
                    <th>استان</th>
                    <th>شهرستان</th>
                    <th>جنسیت</th>
                    <th>کاربر</th>
                    <th>وضعیت</th>
                    <th>تاریخ انتصاب</th>
                    <th>تاریخ لغو</th>
                    <th>توسط</th>
                    <th>عملیات</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $assignments as $row ) :
                    $user       = get_userdata( (int) $row->user_id );
                    $assignedBy = get_userdata( (int) $row->assigned_by );
                    $basic_link = admin_url( 'users.php?page=imao-basic-info&user_id=' . (int) $row->user_id );
                    $login_link = wp_nonce_url( admin_url( 'admin-post.php?action=imao_login_as&user_id=' . (int) $row->user_id ), 'imao_login_as_' . (int) $row->user_id );
                    ?>
                    <tr>
                        <td><?= esc_html( $provinces[ $row->province_code ] ?? $row->province_code ); ?></td>
                        <td><?= esc_html( $row->city_name ); ?></td>
                        <td><?= esc_html( $genders[ $row->gender ] ?? '—' ); ?></td>
                        <td>
                            <?php if ( $user instanceof WP_User ) : ?>
                                <a href="<?= esc_url( $basic_link ); ?>"><?= esc_html( $user->display_name ); ?></a>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><?= esc_html( $row->status === 'active' ? 'فعال' : 'غیرفعال' ); ?></td>
                        <td><?= esc_html( $row->assigned_at ); ?></td>
                        <td><?= esc_html( $row->deactivated_at ?: '—' ); ?></td>
                        <td><?= $assignedBy instanceof WP_User ? esc_html( $assignedBy->display_name ) : '—'; ?></td>
                        <td class="imao-actions">
                            <input type="hidden" name="assignment[<?= esc_attr( (string) $row->id ); ?>][id]" value="<?= esc_attr( (string) $row->id ); ?>">
                            <?php if ( $row->status === 'active' ) : ?>
                                <button class="button deactivate-btn" name="assignment[<?= esc_attr( (string) $row->id ); ?>][act]" value="deactivate">لغو</button>
                            <?php endif; ?>
                            <button class="button delete-btn" name="assignment[<?= esc_attr( (string) $row->id ); ?>][act]" value="delete">حذف</button>
                            <button type="button" class="button edit-btn" data-type="city"
                                    data-id="<?= esc_attr( (string) $row->id ); ?>"
                                    data-user="<?= esc_attr( (string) $row->user_id ); ?>"
                                    data-province="<?= esc_attr( $row->province_code ); ?>"
                                    data-city="<?= esc_attr( $row->city_name ); ?>"
                                    data-gender="<?= esc_attr( $row->gender ); ?>"
                                    data-status="<?= esc_attr( $row->status ); ?>">
                                ویرایش
                            </button>
                            <?php if ( $user instanceof WP_User ) : ?>
                                <a class="button" href="<?= esc_url( $login_link ); ?>">ورود</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            </table>
            <input type="hidden" name="imao_city_action" value="1">
        </form>
        <div id="city-edit-panel" class="imao-edit-panel" style="display:none;">
            <h3>ویرایش نماینده شهرستان</h3>
            <form method="post">
                <?php wp_nonce_field( 'imao_city_edit' ); ?>
                <input type="hidden" name="edit_city[id]" id="edit_city_id">
                <table class="form-table">
                    <tr>
                        <th>کاربر</th>
                        <td>
                            <select name="edit_city[user_id]" id="edit_city_user" class="crm-select2" style="width:320px;" required>
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
                            <select name="edit_city[province_code]" id="edit_city_province" class="crm-select2" style="width:320px;" required>
                                <option value="">— استان —</option>
                                <?php foreach ( $provinces as $code => $name ) : ?>
                                    <option value="<?= esc_attr( $code ); ?>"><?= esc_html( $name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>شهرستان</th>
                        <td>
                            <select name="edit_city[city_name]" id="edit_city_name" class="crm-select2" style="width:320px;" required>
                                <option value="">— انتخاب کنید —</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>جنسیت</th>
                        <td>
                            <select name="edit_city[gender]" id="edit_city_gender" class="crm-select2" style="width:320px;" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ( $genders as $g_key => $g_label ) : ?>
                                    <option value="<?= esc_attr( $g_key ); ?>"><?= esc_html( $g_label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th>وضعیت</th>
                        <td>
                            <select name="edit_city[status]" id="edit_city_status" class="crm-select2" style="width:320px;" required>
                                <option value="active">فعال</option>
                                <option value="inactive">غیرفعال</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <p><button type="submit" class="button button-primary">ذخیره تغییرات</button></p>
                <input type="hidden" name="imao_edit_city" value="1">
            </form>
        </div>
        <?php
    }

    private function handle_province_actions(): string {
        $notice = '';
        $actions = $_POST['assignment'] ?? [];
        foreach ( $actions as $row ) {
            $id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
            $act = sanitize_text_field( $row['act'] ?? '' );
            if ( ! $id || ! $act ) {
                continue;
            }
            if ( $act === 'deactivate' ) {
                $result = $this->manager->deactivate_province_assignment( $id, get_current_user_id() );
            } else {
                $result = $this->manager->delete_province_assignment( $id );
            }
            if ( ! ( $result['ok'] ?? false ) ) {
                $msg    = esc_html( $result['message'] ?? 'خطا در به‌روزرسانی.' );
                $notice = '<div class="notice notice-error"><p>' . $msg . '</p></div>';
            }
        }
        return $notice;
    }

    private function handle_city_actions(): string {
        $notice = '';
        $actions = $_POST['assignment'] ?? [];
        foreach ( $actions as $row ) {
            $id  = isset( $row['id'] ) ? (int) $row['id'] : 0;
            $act = sanitize_text_field( $row['act'] ?? '' );
            if ( ! $id || ! $act ) {
                continue;
            }
            if ( $act === 'deactivate' ) {
                $result = $this->manager->deactivate_city_assignment( $id, get_current_user_id() );
            } else {
                $result = $this->manager->delete_city_assignment( $id );
            }
            if ( ! ( $result['ok'] ?? false ) ) {
                $msg    = esc_html( $result['message'] ?? 'خطا در به‌روزرسانی.' );
                $notice = '<div class="notice notice-error"><p>' . $msg . '</p></div>';
            }
        }
        return $notice;
    }

    private function handle_province_edit(): string {
        $data   = $_POST['edit_province'] ?? [];
        $id     = isset( $data['id'] ) ? (int) $data['id'] : 0;
        $user   = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
        $prov   = sanitize_text_field( $data['province_code'] ?? '' );
        $gender = sanitize_text_field( $data['gender'] ?? '' );
        $status = sanitize_text_field( $data['status'] ?? '' );
        $result = $this->manager->update_province_assignment( $id, $user, $prov, $gender, $status, get_current_user_id() );
        if ( $result['ok'] ?? false ) {
            return '<div class="updated"><p>رکورد ویرایش شد.</p></div>';
        }
        $msg = esc_html( $result['message'] ?? 'خطا در ویرایش.' );
        return '<div class="notice notice-error"><p>' . $msg . '</p></div>';
    }

    private function handle_city_edit(): string {
        $data   = $_POST['edit_city'] ?? [];
        $id     = isset( $data['id'] ) ? (int) $data['id'] : 0;
        $user   = isset( $data['user_id'] ) ? (int) $data['user_id'] : 0;
        $prov   = sanitize_text_field( $data['province_code'] ?? '' );
        $city   = sanitize_text_field( $data['city_name'] ?? '' );
        $gender = sanitize_text_field( $data['gender'] ?? '' );
        $status = sanitize_text_field( $data['status'] ?? '' );
        $result = $this->manager->update_city_assignment( $id, $user, $prov, $city, $gender, $status, get_current_user_id() );
        if ( $result['ok'] ?? false ) {
            return '<div class="updated"><p>رکورد ویرایش شد.</p></div>';
        }
        $msg = esc_html( $result['message'] ?? 'خطا در ویرایش.' );
        return '<div class="notice notice-error"><p>' . $msg . '</p></div>';
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

    public function enqueue_front_assets(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        if ( function_exists( 'is_account_page' ) && ! is_account_page() ) {
            return;
        }
        $base = plugin_dir_url( IMAO_PLUGIN_FILE ) . 'assets/';
        wp_enqueue_style( 'imao-select2', $base . 'css/select2.min.css' );
        wp_enqueue_script( 'imao-select2', $base . 'js/select2.min.js', [ 'jquery' ], null, true );
        wp_enqueue_script( 'imao-city-rep-form', $base . 'js/city-rep-form.js', [ 'jquery' ], null, true );
        wp_localize_script(
            'imao-city-rep-form',
            'IMAOCityRep',
            [
                'ajax'  => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'imao_city_rep_lookup' ),
            ]
        );
    }

    public function ajax_lookup_user_national(): void {
        check_ajax_referer( 'imao_city_rep_lookup', 'nonce' );
        if ( ! $this->current_user_is_province_rep() ) {
            wp_send_json_error( [ 'message' => 'دسترسی مجاز نیست.' ], 403 );
        }
        $national_id = preg_replace( '/\D+/', '', (string) ( $_POST['national_id'] ?? '' ) );
        if ( ! $national_id ) {
            wp_send_json_error( [ 'message' => 'کد ملی الزامی است.' ], 400 );
        }
        $user = $this->manager->find_user_by_national_id( $national_id );
        if ( ! $user instanceof WP_User ) {
            wp_send_json_error( [ 'message' => 'کاربری با این کد ملی یافت نشد.' ], 404 );
        }
        wp_send_json_success(
            [
                'user_id' => $user->ID,
                'name'    => $user->display_name,
            ]
        );
    }

    public function render_account_endpoint(): void {
        echo do_shortcode( '[crm_city_representatives]' );
    }

    public function city_representatives_shortcode(): string {
        $this->enqueue_front_assets();
        if ( ! $this->current_user_is_province_rep() ) {
            return '<div class="woocommerce-error">شما دسترسی لازم برای این بخش را ندارید.</div>';
        }

        $province = $this->manager->get_active_province_for_user( get_current_user_id() );
        if ( ! $province ) {
            return '<div class="woocommerce-info">استانی برای شما ثبت نشده است.</div>';
        }

        $form = new CityRepresentativeForm( $this->manager, (string) $province['province_code'] );
        return $form->render();
    }

    private function current_user_is_province_rep(): bool {
        $user = wp_get_current_user();
        return $user instanceof WP_User && in_array( self::ROLE_PROVINCE, (array) $user->roles, true );
    }
}
