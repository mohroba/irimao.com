<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\FieldLabel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use WC_Product_Simple;
use WP_Post;
use WP_User;

class Courses
{
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_LINKED_COURSE = '_linked_post_id';
    private const META_PAYOUTS = '_course_payouts';
    private const META_PAYOUT_MODE = '_course_payout_mode';
    private const META_MANUAL = '_manual_attendees';

    /**
     * Fields for course details meta box.
     *
     * @return array<string,string>
     */
    public static function detail_fields(): array
    {
        return ['course_code' => 'کد', 'start_date' => 'تاریخ شروع', 'end_date' => 'تاریخ پایان', 'exam_date' => 'تاریخ آزمون', 'registration_start' => 'شروع ثبت‌نام', 'registration_end' => 'پایان ثبت‌نام', 'attendance' => 'نوع حضور', 'course_time' => 'ساعت برگزاری دوره', 'organizer' => 'مسئول برگزاری', 'organizer_tel' => 'شماره همراه مسئول برگزاری', 'instructor' => 'مدرس دوره', 'examiner' => 'ممتحن', 'supervisor' => 'ناظر', 'address' => 'آدرس محل برگزاری', 'min_degree' => 'حداقل درجه فنی', 'points' => 'امتیاز دوره', 'price' => 'شهریه دوره (تومان)',];
    }

    /**
     * Province names used for the board taxonomy.
     *
     * @return array<string,string> slug => name
     */
    public static function province_terms(): array
    {
        $file = dirname(__DIR__, 2) . '/provinces.json';
        $data = json_decode(@file_get_contents($file) ?: '[]', true);
        $terms = [];
        if (is_array($data)) {
            foreach ($data as $province) {
                $name = $province['name'] ?? '';
                $slug = $province['slug'] ?? '';
                if ($name && $slug && !isset($terms[$slug])) {
                    $terms[$slug] = $name;
                }
            }
        }
        return $terms;
    }

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomies'], 5);
        add_action('init', [$this, 'populate_board_terms'], 6);
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('manage_course_posts_columns', [$this, 'add_export_column']);
        add_action('manage_course_posts_custom_column', [$this, 'render_export_column'], 10, 2);
        add_action('admin_post_export_course_attendees', [$this, 'export_attendees']);
    }

    public function register_taxonomies(): void
    {
        $tax = function (string $slug, string $singular, string $plural, bool $hier = false): void {
            $labels = ['name' => $plural, 'singular_name' => $singular, 'add_new_item' => "افزودن $singular جدید", 'edit_item' => "ویرایش $singular", 'search_items' => "جستجوی $plural", 'all_items' => "همه $plural",];
            $args = ['hierarchical' => $hier, 'labels' => $labels, 'public' => true, 'show_admin_column' => true, 'rewrite' => ['slug' => $slug], 'show_in_rest' => true,];
            register_taxonomy($slug, 'course', $args);
        };

        // Make gender taxonomy hierarchical to allow parent/child terms
        $tax('gender', 'جنسیت', 'جنسیت', true);
        $tax('board', 'استان', 'استان‌ها', true);
        $tax('course_type', 'نوع دوره', 'انواع دوره');
        $tax('age_category', 'رده سنی', 'رده‌های سنی', true);
        $tax('level', 'سطح', 'سطوح', true);
    }

    /**
     * Ensure board taxonomy is populated with province terms.
     */
    public function populate_board_terms(): void
    {
        if (!function_exists('wp_insert_term') || !function_exists('term_exists')) {
            return;
        }
        foreach (self::province_terms() as $slug => $name) {
            if (!term_exists($slug, 'board')) {
                wp_insert_term($name, 'board', ['slug' => $slug]);
            }
        }
    }

    public function register_cpt(): void
    {
        $labels = ['name' => 'دوره‌ها', 'singular_name' => 'دوره', 'add_new' => 'افزودن دوره', 'add_new_item' => 'دورهٔ جدید', 'edit_item' => 'ویرایش دوره', 'new_item' => 'دورهٔ جدید', 'view_item' => 'مشاهدهٔ دوره', 'search_items' => 'جستجوی دوره', 'menu_name' => 'دوره‌ها',];

        register_post_type('course', ['labels' => $labels, 'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-welcome-learn-more', 'has_archive' => true, 'rewrite' => ['slug' => 'courses'], 'supports' => ['title', 'editor', 'thumbnail'], 'show_in_rest' => true,]);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('crm_details', 'جزئیات', [$this, 'render_details_box'], 'course', 'normal', 'high');
        add_meta_box('crm_payouts', 'ذی‌نفعان', [$this, 'render_payouts_box'], 'course', 'normal', 'default');
        add_meta_box('crm_manual', 'افزودن شرکت کننده به صورت دستی', [$this, 'render_manual_box'], 'course', 'side', 'default');
        add_meta_box('crm_attendees', 'شرکت‌کنندگان', [$this, 'render_attendees_box'], 'course', 'side', 'default');
    }

    public function render_details_box(WP_Post $post): void
    {
        wp_nonce_field('crm_save_details', 'crm_details_nonce');
        $val = static fn(string $k) => esc_attr(get_post_meta($post->ID, $k, true));
        $fields = self::detail_fields();
        $number_field = ['points'];
        $tel_fields = ['organizer_tel'];
        $date_fields = ['start_date', 'end_date', 'exam_date', 'registration_start', 'registration_end'];
        echo '<table class="form-table striped"><tbody>';
        foreach ($fields as $k => $label) {
            $type = 'text';
            $class = '';
            if (in_array($k, $date_fields, true)) {
                $class = 'class="crm-date" data-jdp data-jdp-only-date';
            } elseif ($k === 'price') {
                $type = 'number';
                $class = 'min="0" step="1000"';
            } elseif (in_array($k, $number_field, true)) {
                $type = 'number';
            } elseif (in_array($k, $tel_fields, true)) {
                $type = 'tel';
            }
            printf('<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%" %5$s></td></tr>', esc_attr($k), esc_html($label), $type, $val($k), $class);
        }
        echo '</tbody></table>';
    }

    public function render_payouts_box(WP_Post $post): void
    {
        wp_nonce_field('crm_save_payouts', 'crm_payouts_nonce');
        $mode       = get_post_meta( $post->ID, self::META_PAYOUT_MODE, true ) ?: 'user';
        $rows       = (array) get_post_meta( $post->ID, self::META_PAYOUTS, true );
        $user_rows  = [];
        $role_rows  = [];
        foreach ( $rows as $r ) {
            if ( ( $r['recipient_type'] ?? 'user' ) === 'predefined' ) {
                $role_rows[] = $r;
            } else {
                $user_rows[] = $r;
            }
        }
        $users_opts = function ( $sel ) {
            $opts = '';
            foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $u ) {
                $opts .= sprintf( '<option value="%d"%s>%s</option>', $u->ID, selected( $u->ID, $sel, false ), esc_html( $u->display_name ) );
            }
            return $opts;
        };
        $payout_roles = \IMAOCustom\Plugin::get_payout_roles();
        $predef_opts  = function ( $sel ) use ( $payout_roles ) {
            $opts = '<option value=""></option>';
            foreach ( $payout_roles as $slug => $data ) {
                $opts .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $slug ), selected( $slug, $sel, false ), esc_html( $data['label'] ) );
            }
            return $opts;
        };

        echo '<p>'
            . '<label><input type="radio" name="payout_mode" value="user"' . checked( 'user', $mode, false ) . '>کاربر</label> '
            . '<label style="margin-right:15px"><input type="radio" name="payout_mode" value="predefined"' . checked( 'predefined', $mode, false ) . '>پیش‌فرض</label>'
            . '</p>';

        echo '<div class="payout-user-form"' . ( $mode === 'predefined' ? ' style="display:none"' : '' ) . '>';
        echo '<table class="widefat striped" id="crm-payout-user-table"><thead><tr><th>کاربر</th><th>نوع</th><th>مقدار</th><th></th></tr></thead><tbody id="crm-payout-user-body">';
        $userRow = function ( $uid = '', $type = 'percent', $val = '' ) use ( $users_opts ) {
            return '<tr>'
                . '<td><select name="payout_user_id[]" class="crm-select2" style="width:100%">' . $users_opts( $uid ) . '</select></td>'
                . '<td><select name="payout_user_type[]"><option value="percent"' . selected( 'percent', $type, false ) . '>درصد</option><option value="fixed"' . selected( 'fixed', $type, false ) . '>مبلغ ثابت</option></select></td>'
                . '<td><input type="number" step="0.01" name="payout_user_value[]" value="' . esc_attr( $val ) . '"></td>'
                . '<td><span class="dashicons dashicons-no-alt crm-remove-row" style="cursor:pointer;color:#c00"></span></td>'
                . '</tr>';
        };
        foreach ( $user_rows as $r ) {
            echo $userRow( $r['user_id'] ?? '', $r['type'] ?? 'percent', $r['value'] ?? '' );
        }
        echo '</tbody></table><button type="button" class="button" id="crm-add-payout-user">افزودن</button></div>';

        echo '<div class="payout-role-form"' . ( $mode === 'predefined' ? '' : ' style="display:none"' ) . '>';
        echo '<table class="widefat striped" id="crm-payout-role-table"><thead><tr><th>نقش</th><th>نوع</th><th>مقدار</th><th></th></tr></thead><tbody id="crm-payout-role-body">';
        $roleRow = function ( $role = '', $type = 'percent', $val = '' ) use ( $predef_opts ) {
            return '<tr>'
                . '<td><select name="payout_role[]" style="width:100%">' . $predef_opts( $role ) . '</select></td>'
                . '<td><select name="payout_type[]"><option value="percent"' . selected( 'percent', $type, false ) . '>درصد</option><option value="fixed"' . selected( 'fixed', $type, false ) . '>مبلغ ثابت</option></select></td>'
                . '<td><input type="number" step="0.01" name="payout_value[]" value="' . esc_attr( $val ) . '"></td>'
                . '<td><span class="dashicons dashicons-no-alt crm-remove-row" style="cursor:pointer;color:#c00"></span></td>'
                . '</tr>';
        };
        foreach ( $role_rows as $r ) {
            echo $roleRow( $r['role'] ?? '', $r['type'] ?? 'percent', $r['value'] ?? '' );
        }
        echo '</tbody></table><button type="button" class="button" id="crm-add-payout-role">افزودن</button></div>';

        ?>
        <script>jQuery(function ($) {
                function setup(btn, tbody, tpl) {
                    $(btn).on('click', function () { $(tbody).append(tpl); });
                }
                setup('#crm-add-payout-user', '#crm-payout-user-body', `<?php echo addslashes($userRow()); ?>`);
                setup('#crm-add-payout-role', '#crm-payout-role-body', `<?php echo addslashes($roleRow()); ?>`);
                $(document).on('click', '.crm-remove-row', function () { $(this).closest('tr').remove(); });
                $('input[name="payout_mode"]').on('change', function(){
                    $('.payout-user-form').toggle(this.value === 'user');
                    $('.payout-role-form').toggle(this.value === 'predefined');
                });
            });</script>
        <?php
    }

    public function render_manual_box(WP_Post $post): void
    {
        wp_nonce_field('crm_save_manual', 'crm_manual_nonce');
        $att = (array)get_post_meta($post->ID, self::META_MANUAL, true);

        echo '<p style="color:#666;margin-top:0">افزودن دستی شرکت‌کننده (نیاز به خرید ندارد).</p>';
        echo '<p><select multiple name="manual_attendees[]" class="crm-select2" style="width:100%">';
        foreach (get_users(['fields' => ['ID', 'display_name']]) as $u) {
            printf('<option value="%d"%s>%s</option>', $u->ID, selected(in_array($u->ID, $att, true), true, false), esc_html($u->display_name));
        }
        echo '</select></p><p style="font-size:12px">نگه‌داشتن CTRL برای چند انتخاب.</p>';
    }

    public function render_attendees_box(WP_Post $post): void
    {
        // Never show attendees on new/unsaved posts
        if ( empty($post->ID) || in_array($post->post_status, ['auto-draft','draft','pending'], true) ) {
            echo '<p style="color:#666">پس از ذخیره/انتشار دوره و ساخت محصول مرتبط، شرکت‌کنندگانِ خریدار نمایش داده می‌شوند.</p>';
            return;
        }

        // Only show buyers once a linked product exists
        $prod_id = (int) get_post_meta($post->ID, self::META_LINKED_PRODUCT, true);
        if ( ! $prod_id ) {
            echo '<p style="color:#666">برای نمایش شرکت‌کنندگان، ابتدا دوره را ذخیره/انتشار کنید تا محصول مرتبط ساخته شود.</p>';
            return;
        }

        $users = $this->get_attendees($post->ID); // buyers only
        if ( empty($users) ) {
            echo '<p>شرکت‌کننده‌ای ثبت نشده است.</p>';
            return;
        }

        $fields = $this->attendee_fields();
        echo '<div style="max-width:100%;overflow:auto">';
        echo '<table id="crm-attendees-table" class="wp-list-table widefat striped"><thead><tr>';
        foreach ($fields as $lbl) { echo '<th>' . esc_html($lbl) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ($users as $u) {
            $row = $this->attendee_row($u);
            echo '<tr>';
            foreach ($fields as $key => $lbl) {
                echo '<td>' . esc_html( (string) ($row[$key] ?? '') ) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }

    /**
     * Get users who purchased the linked product.
     *
     * @return WP_User[]
     */
    private function get_attendees(int $course_id): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $prod_id = (int)get_post_meta($course_id, self::META_LINKED_PRODUCT, true);
        if (!$prod_id) {
            return [];
        }
        global $wpdb;
        $order_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items oi
                 JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id = oim.order_item_id
                 WHERE oi.order_item_type = 'line_item' AND oim.meta_key = '_product_id' AND oim.meta_value = %d",
                $prod_id
            )
        );
        if (!$order_ids) {
            return [];
        }
        $orders = wc_get_orders(['limit' => -1, 'status' => ['processing', 'completed'], 'include' => $order_ids]);
        $users = [];
        foreach ($orders as $order) {
            $uid = (int)$order->get_user_id();
            if (!$uid || isset($users[$uid])) {
                continue;
            }
            if ($user = get_user_by('id', $uid)) {
                $users[$uid] = $user;
            }
        }
        return array_values($users);
    }

    /**
     * Field map for attendee data.
     *
     * @return array<string,string>
     */
    private function attendee_fields(): array
    {
        return ['ID' => 'ID', 'display_name' => 'نام', 'billing_email' => 'ایمیل', 'billing_phone' => 'شماره موبایل', 'national_id' => 'کد ملی', 'gender' => 'جنسیت', 'first_name_fa' => 'نام (فا)', 'last_name_fa' => 'نام خانوادگی (فا)', 'first_name_en' => 'نام (En)', 'last_name_en' => 'نام خانوادگی (En)', 'father_name' => 'نام پدر', 'birth_date' => 'تاریخ تولد', 'birth_province' => 'استان محل تولد', 'birth_city' => 'شهرستان محل تولد', 'marital_status' => 'وضعیت تأهل', 'education_status' => 'وضعیت تحصیلی', 'military_status' => 'وضعیت خدمت', 'residence_province' => 'استان محل سکونت', 'residence_city' => 'شهرستان محل سکونت', 'postal_code' => 'کدپستی', 'residence_address' => 'آدرس', 'iban' => 'شماره شبا', 'card_number' => 'شماره کارت', 'coach_id' => 'مربی', 'club_id' => 'باشگاه',];
    }

    /**
     * Build a row of attendee data.
     *
     * @return array<string,string|int>
     */
    private function attendee_row(WP_User $u): array
    {
        $row = [];
        foreach ($this->attendee_fields() as $key => $lbl) {
            switch ($key) {
                case 'ID':
                    $row[$key] = $u->ID;
                    break;
                case 'display_name':
                    $row[$key] = $u->display_name;
                    break;
                case 'billing_email':
                    $row[$key] = get_user_meta($u->ID, 'billing_email', true) ?: $u->user_email;
                    break;
                case 'coach_id':
                case 'club_id':
                    $id = (int)get_user_meta($u->ID, $key, true);
                    if (!$id) {
                        $row[$key] = '';
                        break;
                    }
                    if ($key === 'club_id') {
                        $club_name = get_user_meta($id, 'club_name', true);
                        if ($club_name) {
                            $row[$key] = $club_name;
                            break;
                        }
                    }
                    $target = get_user_by('id', $id);
                    $row[$key] = $target ? $target->display_name : $id;
                    break;
                default:
                    $meta = get_user_meta($u->ID, $key, true);
                    $row[$key] = FieldLabel::get($key, $meta);
            }
        }
        return $row;
    }

    /**
     * Generate XLSX content for attendees.
     */
    protected function build_xlsx(array $users): string
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->fromArray([array_values($this->attendee_fields())]);
        $row = 2;
        foreach ($users as $u) {
            $active->fromArray([array_values($this->attendee_row($u))], null, 'A' . $row);
            $row++;
        }
        $writer = new Xlsx($sheet);
        ob_start();
        $writer->save('php://output');
        return (string)ob_get_clean();
    }

    public function add_export_column(array $cols): array
    {
        $cols['crm_export'] = 'شرکت‌کنندگان';
        return $cols;
    }

    public function render_export_column(string $col, int $post_id): void
    {
        if ($col !== 'crm_export') {
            return;
        }
        $url = wp_nonce_url(admin_url('admin-post.php?action=export_course_attendees&course=' . $post_id), 'export_course_attendees_' . $post_id);
        echo '<a class="button" href="' . esc_url($url) . '">خروجی اکسل</a>';
    }

    /**
     * Buyers + manual attendees (unique) for a course.
     *
     * @return WP_User[]
     */
    private function get_all_attendees(int $course_id): array
    {
        // Buyers
        $buyers = $this->get_attendees($course_id);
        $indexed = [];
        foreach ($buyers as $u) {
            $indexed[$u->ID] = $u;
        }

        // Manual
        $manual_ids = array_map('intval', (array)get_post_meta($course_id, self::META_MANUAL, true));
        if ($manual_ids) {
            foreach ($manual_ids as $uid) {
                if ($uid && !isset($indexed[$uid])) {
                    $u = get_user_by('id', $uid);
                    if ($u) {
                        $indexed[$uid] = $u;
                    }
                }
            }
        }

        return array_values($indexed);
    }

    public function export_attendees(): void
    {
        $course_id = (int)($_GET['course'] ?? 0);
        if (!$course_id) {
            wp_die('Course not specified.');
        }
        check_admin_referer('export_course_attendees_' . $course_id);

        // Export buyers ∪ manual
        $users = $this->get_all_attendees($course_id);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="course-' . $course_id . '-attendees.xlsx"');
        echo $this->build_xlsx($users);
        exit;
    }


    public function save_meta(int $post_id, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if ($post->post_type !== 'course') {
            return;
        }

        if (isset($_POST['crm_details_nonce'])) {
            foreach (array_keys(self::detail_fields()) as $k) {
                if (!isset($_POST[$k])) {
                    continue;
                }
                if ($k === 'price') {
                    $val = absint($_POST[$k]);
                } else {
                    $val = sanitize_text_field($_POST[$k]);
                }
                update_post_meta($post_id, $k, $val);
            }
        }

        if (isset($_POST['crm_payouts_nonce'])) {
            $mode = sanitize_text_field( $_POST['payout_mode'] ?? 'user' );
            update_post_meta( $post_id, self::META_PAYOUT_MODE, $mode );
            $rows = [];
            if ( $mode === 'predefined' ) {
                foreach ( (array) ( $_POST['payout_role'] ?? [] ) as $i => $role ) {
                    $role = sanitize_text_field( $role );
                    if ( $role === '' ) {
                        continue;
                    }
                    $rows[] = [
                        'recipient_type' => 'predefined',
                        'role'           => $role,
                        'type'           => sanitize_text_field( $_POST['payout_type'][ $i ] ?? 'percent' ),
                        'value'          => (float) ( $_POST['payout_value'][ $i ] ?? 0 ),
                    ];
                }
            } else {
                foreach ( (array) ( $_POST['payout_user_id'] ?? [] ) as $i => $uid ) {
                    $uid = (int) $uid;
                    if ( ! $uid ) {
                        continue;
                    }
                    $rows[] = [
                        'recipient_type' => 'user',
                        'user_id'        => $uid,
                        'type'           => sanitize_text_field( $_POST['payout_user_type'][ $i ] ?? 'percent' ),
                        'value'          => (float) ( $_POST['payout_user_value'][ $i ] ?? 0 ),
                    ];
                }
            }
            update_post_meta( $post_id, self::META_PAYOUTS, $rows );
        }

        if (isset($_POST['crm_manual_nonce'])) {
            $att = array_map('intval', $_POST['manual_attendees'] ?? []);
            update_post_meta($post_id, self::META_MANUAL, $att);
        }

        $this->sync_product($post_id);
    }

    private function sync_product(int $post_id): void
    {
        if (!class_exists('WC_Product')) {
            return;
        }
        $price = (float)get_post_meta($post_id, 'price', true);
        $prod_id = (int)get_post_meta($post_id, self::META_LINKED_PRODUCT, true);
        if ($prod_id && ($prod = wc_get_product($prod_id))) {
            $prod->set_name(get_the_title($post_id));
            $prod->set_regular_price($price);
            $prod->save();
        } else {
            $prod = new WC_Product_Simple();
            $prod->set_name(get_the_title($post_id));
            $prod->set_regular_price($price);
            $prod->set_virtual(true);
            $prod->set_catalog_visibility('hidden');
            $prod_id = $prod->save();
            update_post_meta($post_id, self::META_LINKED_PRODUCT, $prod_id);
            update_post_meta($prod_id, self::META_LINKED_COURSE, $post_id);
        }
    }

    public function enqueue_admin_assets(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'course') {
            return;
        }
        $url = plugin_dir_url(dirname(__DIR__));
        wp_enqueue_style('imao-jdp', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0');
        wp_enqueue_style('imao-select2', $url . 'assets/css/select2.min.css', [], '1.0.0');
        wp_enqueue_style('imao-dt', $url . 'assets/css/jquery.dataTables.min.css', [], '1.0.0');
        wp_enqueue_script('imao-jdp', $url . 'assets/js/jalalidatepicker.min.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('imao-select2', $url . 'assets/js/select2.min.js', ['jquery'], '1.0.0', true);
        wp_enqueue_script('imao-dt', $url . 'assets/js/jquery.dataTables.min.js', ['jquery'], '1.0.0', true);
        wp_add_inline_script('imao-jdp', 'jQuery(function($){$(".crm-select2").select2({dir:"rtl",width:"resolve"});jalaliDatepicker.startWatch();});');
        wp_add_inline_script('imao-dt', 'jQuery(function($){$("#crm-attendees-table").DataTable({language:{url:"https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json"},pageLength:20});});');
    }
}
