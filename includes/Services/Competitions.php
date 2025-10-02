<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\Date;
use IMAOCustom\Helpers\FieldLabel;
use IMAOCustom\Helpers\AgeCategory;
use IMAOCustom\Helpers\CompetitionTypeAssignments;
use IMAOCustom\Helpers\UserMeta;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use WC_Product_Simple;
use WP_Post;
use WP_Query;
use WP_User;
use WP_Term;

class Competitions
{
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_LINKED_COMPETITION = '_linked_post_id';
    private const META_PAYOUTS = '_competition_payouts';
    private const META_PAYOUT_MODE = '_competition_payout_mode';
    private const META_MANUAL = '_manual_attendees';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomies'], 5);
        add_action('init', [$this, 'populate_board_terms'], 6);
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 3);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('manage_competition_posts_columns', [$this, 'add_export_column']);
        add_action('manage_competition_posts_custom_column', [$this, 'render_export_column'], 10, 2);
        add_action('admin_post_export_competition_attendees', [$this, 'export_attendees']);
        add_shortcode('crm_competitions_list', [$this, 'competitions_list_shortcode']);
        add_shortcode('crm_competition_details', [$this, 'competition_details_shortcode']);
        add_shortcode('crm_user_competitions', [$this, 'user_competitions_shortcode']);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 2);
        add_filter('woocommerce_get_item_data', [$this, 'add_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_line_item_meta'], 10, 2);
    }

    public function register_taxonomies(): void
    {
        $tax = function (string $slug, string $singular, string $plural, bool $hier = false): void {
            $labels = ['name' => $plural, 'singular_name' => $singular, 'add_new_item' => "افزودن $singular جدید", 'edit_item' => "ویرایش $singular", 'search_items' => "جستجوی $plural", 'all_items' => "همه $plural",];
            $args = ['hierarchical' => $hier, 'labels' => $labels, 'public' => true, 'show_admin_column' => true, 'rewrite' => ['slug' => $slug], 'show_in_rest' => true,];
            if (taxonomy_exists($slug)) {
                register_taxonomy_for_object_type($slug, 'competition');
            } else {
                register_taxonomy($slug, ['competition'], $args);
            }
        };

        // Make gender taxonomy hierarchical
        $tax('gender', 'جنسیت', 'جنسیت', true);
        $tax('board', 'استان', 'استان‌ها', true);
        $tax('competition_type', 'نوع مسابقه', 'انواع مسابقه', true);
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
        foreach (Courses::province_terms() as $slug => $name) {
            if (!term_exists($slug, 'board')) {
                wp_insert_term($name, 'board', ['slug' => $slug]);
            }
        }
    }

    public function register_cpt(): void
    {
        $labels = ['name' => 'مسابقات', 'singular_name' => 'مسابقه', 'add_new' => 'افزودن مسابقه', 'add_new_item' => 'مسابقهٔ جدید', 'edit_item' => 'ویرایش مسابقه', 'new_item' => 'مسابقهٔ جدید', 'view_item' => 'مشاهدهٔ مسابقه', 'search_items' => 'جستجوی مسابقه', 'menu_name' => 'مسابقات',];

        register_post_type('competition', ['labels' => $labels, 'public' => true, 'show_ui' => true, 'show_in_menu' => true, 'menu_icon' => 'dashicons-awards', 'has_archive' => true, 'rewrite' => ['slug' => 'competitions'], 'supports' => ['title', 'thumbnail'], 'taxonomies' => ['gender', 'board', 'competition_type', 'age_category', 'level'], 'show_in_rest' => true,]);
    }

    public function add_meta_boxes(): void
    {
        add_meta_box('crm_details', 'جزئیات', [$this, 'render_details_box'], 'competition', 'normal', 'high');
        add_meta_box('crm_type_assignments', 'انواع مسابقه در رده‌ها', [$this, 'render_type_assignments_box'], 'competition', 'normal', 'default');
        add_meta_box('crm_payouts', 'ذی‌نفعان', [$this, 'render_payouts_box'], 'competition', 'normal', 'default');
        add_meta_box('crm_manual', 'افزودن شرکت کننده به صورت دستی', [$this, 'render_manual_box'], 'competition', 'side', 'default');
        add_meta_box('crm_attendees', 'شرکت‌کنندگان', [$this, 'render_attendees_box'], 'competition', 'side', 'default');
    }

    public function render_details_box(WP_Post $post): void
    {
        wp_nonce_field('crm_save_details', 'crm_details_nonce');
        $val = static fn(string $k) => esc_attr(get_post_meta($post->ID, $k, true));
        $fields = self::detail_fields();
        $number_field = [];
        $tel_fields = ['organizer_tel'];
        $date_fields = ['start_date', 'end_date', 'registration_start', 'registration_end', 'weigh_in_start_date', 'weigh_in_end_date'];
        $time_fields = ['weigh_in_start', 'weigh_in_end'];
        echo '<table class="form-table striped"><tbody>';
        foreach ($fields as $k => $label) {
            $type = 'text';
            $class = '';
            if (in_array($k, $date_fields, true)) {
                $class = 'class="crm-date" data-jdp data-jdp-only-date';
            } elseif (in_array($k, $time_fields, true)) {
                $class = 'class="crm-date" data-jdp data-jdp-only-time';
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

    /**
     * Fields for competition details meta box.
     *
     * @return array<string,string>
     */
    public static function detail_fields(): array
    {
        return [
            'competition_code' => 'کد',
            'start_date' => 'تاریخ شروع مسابقه',
            'end_date' => 'تاریخ پایان مسابقه',
            'registration_start' => 'شروع ثبت‌نام',
            'registration_end' => 'پایان ثبت‌نام',
            'weigh_in_start_date' => 'تاریخ شروع وزن‌کشی',
            'weigh_in_start' => 'ساعت شروع وزن‌کشی',
            'weigh_in_end_date' => 'تاریخ پایان وزن‌کشی',
            'weigh_in_end' => 'ساعت پایان وزن‌کشی',
            'organizer' => 'مسئول برگزاری',
            'organizer_tel' => 'شماره همراه مسئول برگزاری',
            'address' => 'آدرس محل برگزاری',
            'min_degree' => 'حداقل درجه فنی',
            'price' => 'هزینه ثبت نام مسابقه (تومان)',
        ];
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
                function setup(btn, tbody, tpl) { $(btn).on('click', function(){ $(tbody).append(tpl); }); }
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

    public function render_type_assignments_box(WP_Post $post): void
    {
        wp_nonce_field('crm_save_type_assignments', 'crm_type_assignments_nonce');
        $assignments = CompetitionTypeAssignments::get_map($post->ID);
        $age_terms   = $this->get_top_level_age_categories();
        $type_terms  = $this->get_competition_type_choices();

        if (!$age_terms) {
            echo '<p style="color:#c00;">ردهٔ سنی‌ای یافت نشد. ابتدا رده‌های سنی را ایجاد کنید.</p>';
            return;
        }
        if (!$type_terms) {
            echo '<p style="color:#c00;">نوع مسابقه‌ای تعریف نشده است. ابتدا انواع مسابقه را ایجاد کنید.</p>';
            return;
        }

        echo '<p style="margin-bottom:10px;color:#555;">برای هر ردهٔ سنی، نوع یا انواع مسابقهٔ مجاز را مشخص کنید. تنها گزینه‌های مرتبط با این مسابقه به شرکت‌کننده نمایش داده می‌شود.</p>';
        echo '<table class="widefat striped" style="max-width:800px">';
        echo '<thead><tr><th>رده سنی</th><th>انواع مسابقه مجاز</th></tr></thead><tbody>';
        foreach ($age_terms as $age) {
            $age_id   = (int) $age->term_id;
            $selected = $assignments[$age_id] ?? [];
            echo '<tr>';
            echo '<th scope="row">' . esc_html($age->name) . '</th>';
            echo '<td>';
            printf('<select name="type_assignments[%d][]" multiple class="crm-select2" style="min-width:300px" size="6">', $age_id);
            foreach ($type_terms as $type) {
                $term   = $type['term'];
                $indent = str_repeat('— ', max(0, $type['depth']));
                $term_id = (int) $term->term_id;
                $is_selected = in_array($term_id, $selected, true) ? ' selected' : '';
                printf(
                    '<option value="%d"%s>%s%s</option>',
                    $term_id,
                    $is_selected,
                    $indent ? esc_html($indent) . ' ' : '',
                    esc_html($term->name)
                );
            }
            echo '</select>';
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * @return array<int,WP_Term>
     */
    private function get_top_level_age_categories(): array
    {
        $terms = get_terms([
            'taxonomy'   => 'age_category',
            'hide_empty' => false,
            'parent'     => 0,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        return array_values(array_filter(array_map(static function ($term) {
            return $term instanceof WP_Term ? $term : null;
        }, $terms)));
    }

    /**
     * Build a flattened list of competition types with depth.
     *
     * @return array<int,array{term:WP_Term,depth:int}>
     */
    private function get_competition_type_choices(): array
    {
        return $this->collect_competition_type_terms();
    }

    /**
     * @param int $parent
     * @param int $depth
     * @return array<int,array{term:WP_Term,depth:int}>
     */
    private function collect_competition_type_terms(int $parent = 0, int $depth = 0): array
    {
        $terms = get_terms([
            'taxonomy'   => 'competition_type',
            'hide_empty' => false,
            'parent'     => $parent,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (!is_array($terms) || empty($terms)) {
            return [];
        }

        $items = [];
        foreach ($terms as $term) {
            if (!($term instanceof WP_Term)) {
                continue;
            }
            $items[] = ['term' => $term, 'depth' => $depth];
            $items   = array_merge($items, $this->collect_competition_type_terms($term->term_id, $depth + 1));
        }

        return $items;
    }

    /**
     * @param int   $competition_id
     * @param array<int,int> $age_ids
     * @param array<int,WP_Term> $type_map
     * @return array<int,WP_Term>
     */
    private function filter_types_for_age(int $competition_id, array $age_ids, array $type_map): array
    {
        if (!$age_ids || !$type_map) {
            return [];
        }

        $type_ids = CompetitionTypeAssignments::types_for_ages($competition_id, $age_ids);
        if (!$type_ids) {
            return [];
        }

        $selected = [];
        foreach ($type_ids as $tid) {
            if (isset($type_map[$tid])) {
                $selected[] = $type_map[$tid];
            }
        }

        return $selected;
    }

    public function render_attendees_box(WP_Post $post): void
    {
        // Never show attendees on new/unsaved posts
        if ( empty($post->ID) || in_array($post->post_status, ['auto-draft','draft','pending'], true) ) {
            echo '<p style="color:#666">پس از ذخیره/انتشار مسابقه و ساخت محصول مرتبط، شرکت‌کنندگانِ خریدار نمایش داده می‌شوند.</p>';
            return;
        }

        // Only show buyers once a linked product exists
        $prod_id = (int) get_post_meta($post->ID, self::META_LINKED_PRODUCT, true);
        if ( ! $prod_id ) {
            echo '<p style="color:#666">برای نمایش شرکت‌کنندگان، ابتدا مسابقه را ذخیره/انتشار کنید تا محصول مرتبط ساخته شود.</p>';
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
    private function get_attendees(int $competition_id): array
    {
        if (!function_exists('wc_get_orders')) {
            return [];
        }
        $prod_id = (int)get_post_meta($competition_id, self::META_LINKED_PRODUCT, true);
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
        $url = wp_nonce_url(admin_url('admin-post.php?action=export_competition_attendees&competition=' . $post_id), 'export_competition_attendees_' . $post_id);
        echo '<a class="button" href="' . esc_url($url) . '">خروجی اکسل</a>';
    }

    public function export_attendees(): void
    {
        $competition_id = (int)($_GET['competition'] ?? 0);
        if (!$competition_id) {
            wp_die('Competition not specified.');
        }
        check_admin_referer('export_competition_attendees_' . $competition_id);

        // Use the merged list
        $users = $this->get_all_attendees($competition_id);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="competition-' . $competition_id . '-attendees.xlsx"');
        echo $this->build_xlsx($users);
        exit;
    }

    /**
     * Buyers + manual attendees (unique).
     *
     * @return WP_User[]
     */
    private function get_all_attendees(int $competition_id): array
    {
        // Buyers
        $buyers = $this->get_attendees($competition_id);

        // Manual IDs
        $manual_ids = array_map('intval', (array)get_post_meta($competition_id, self::META_MANUAL, true));

        // Load manual users
        $manual = [];
        if ($manual_ids) {
            foreach ($manual_ids as $uid) {
                $u = get_user_by('id', $uid);
                if ($u) {
                    $manual[$uid] = $u;
                }
            }
        }

        // Index buyers by ID, then merge
        $all = [];
        foreach ($buyers as $u) {
            $all[$u->ID] = $u;
        }
        foreach ($manual as $uid => $u) {
            $all[$uid] = $u;
        }

        return array_values($all);
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

    public function save_meta(int $post_id, WP_Post $post, bool $update): void
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if ($post->post_type !== 'competition') {
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

        if (isset($_POST['crm_type_assignments_nonce'])) {
            $nonce = $_POST['crm_type_assignments_nonce'];
            if (!function_exists('wp_verify_nonce') || wp_verify_nonce($nonce, 'crm_save_type_assignments')) {
                $map = $_POST['type_assignments'] ?? [];
                if (function_exists('wp_unslash')) {
                    $map = wp_unslash($map);
                }
                CompetitionTypeAssignments::save_map($post_id, is_array($map) ? $map : []);
            }
        }

        // Ensure parent age categories are also assigned when only child terms are selected.
        $age_terms = wp_get_post_terms($post_id, 'age_category', ['fields' => 'ids']);
        if (!is_wp_error($age_terms) && $age_terms) {
            $parents = [];
            foreach ($age_terms as $tid) {
                $term = get_term($tid, 'age_category');
                if ($term && !is_wp_error($term) && $term->parent) {
                    $parents[] = (int) $term->parent;
                }
            }
            if ($parents) {
                $all = array_unique(array_merge($age_terms, $parents));
                wp_set_post_terms($post_id, $all, 'age_category');
            }
        }
        $this->sync_product($post_id);
    }

    private function sync_product(int $competition_id): int
    {
        if (!class_exists('WC_Product')) {
            return 0;
        }
        $price = (float)get_post_meta($competition_id, 'price', true);
        $prod_id = (int)get_post_meta($competition_id, self::META_LINKED_PRODUCT, true);
        if ($prod_id && ($prod = wc_get_product($prod_id))) {
            $prod->set_name(get_the_title($competition_id));
            $prod->set_regular_price($price);
            $prod->save();
        } else {
            $prod = new WC_Product_Simple();
            $prod->set_name(get_the_title($competition_id));
            $prod->set_regular_price($price);
            $prod->set_virtual(true);
            $prod->set_catalog_visibility('hidden');
            $prod_id = $prod->save();
            update_post_meta($competition_id, self::META_LINKED_PRODUCT, $prod_id);
            update_post_meta($prod_id, self::META_LINKED_COMPETITION, $competition_id);
        }
        return $prod_id;
    }

    public function enqueue_admin_assets(): void
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'competition') {
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

    public function add_cart_item_data($data, $prod_id)
    {
        if (isset($_REQUEST['weight_class_term'])) {
            $weight = (int)$_REQUEST['weight_class_term'];
            $data['weight_class_term'] = $weight;
            $term = get_term($weight, 'age_category');
            if ($term && $term->parent) {
                $data['age_category_term'] = (int)$term->parent;
            }
        }
        if (isset($_REQUEST['competition_type_term'])) {
            $data['competition_type_term'] = (int)$_REQUEST['competition_type_term'];
        }
        return $data;
    }

    public function add_item_data($data, $cart_item)
    {
        if (!empty($cart_item['weight_class_term'])) {
            $term = get_term($cart_item['weight_class_term'], 'age_category');
            if ($term) {
                $data[] = ['name' => 'دسته وزنی', 'value' => $term->name];
            }
        }
        if (!empty($cart_item['age_category_term'])) {
            $term = get_term($cart_item['age_category_term'], 'age_category');
            if ($term) {
                $data[] = ['name' => 'رده سنی', 'value' => $term->name];
            }
        }
        if (!empty($cart_item['competition_type_term'])) {
            $term = get_term($cart_item['competition_type_term'], 'competition_type');
            if ($term) {
                $data[] = ['name' => 'نوع مسابقه', 'value' => $term->name];
            }
        }
        return $data;
    }

    public function add_order_line_item_meta($item, $cart_item)
    {
        if (!empty($cart_item['weight_class_term'])) {
            $term = get_term($cart_item['weight_class_term'], 'age_category');
            if ($term) {
                $item->add_meta_data('دسته وزنی', $term->name, true);
            }
        }
        if (!empty($cart_item['age_category_term'])) {
            $term = get_term($cart_item['age_category_term'], 'age_category');
            if ($term) {
                $item->add_meta_data('رده سنی', $term->name, true);
            }
        }
        if (!empty($cart_item['competition_type_term'])) {
            $term = get_term($cart_item['competition_type_term'], 'competition_type');
            if ($term) {
                $item->add_meta_data('نوع مسابقه', $term->name, true);
            }
        }
    }

    public function competitions_list_shortcode(): string
    {
        $gender   = '';
        $age_slug = '';
        if (function_exists('get_current_user_id')) {
            $uid = get_current_user_id();
            if ($uid) {
                $gender = UserMeta::gender_slug($uid);
                $birth  = UserMeta::get($uid, 'birth_date', '');
                if ($birth) {
                    $age = Date::age($birth);
                    if ($age !== null) {
                        $age_slug = AgeCategory::slug_from_age($age);
                    }
                }
            }
        }
        if (!$gender) {
            return '<p>برای مشاهدهٔ لیست مسابقات ابتدا جنسیت خود را در بخش اطلاعات پایه ثبت کنید.</p>';
        }
        if (!$age_slug) {
            return '<p>رده سنی یافت نشد. برای مشاهدهٔ لیست مسابقات از درست بودن تاریخ تولد خود را در بخش اطلاعات پایه اطمینان حاصل نمایید.</p>';
        }

        $q = new WP_Query([
            'post_type'      => 'competition',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => [
                [
                    'taxonomy' => 'gender',
                    'field'    => 'slug',
                    'terms'    => $gender,
                ],
                [
                    'taxonomy' => 'age_category',
                    'field'    => 'slug',
                    'terms'    => $age_slug,
                ],
            ],
        ]);
        $posts = [];
        while ( $q->have_posts() ) {
            $q->the_post();
            $pid   = get_the_ID();
            $start = get_post_meta( $pid, 'registration_start', true );
            $end   = get_post_meta( $pid, 'registration_end', true );
            if ( Date::is_between( $start, $end ) ) {
                $posts[] = get_post();
            }
        }
        wp_reset_postdata();
        if ( ! $posts ) {
            return '<p>مسابقه‌ در حال ثبت نامی موجود نیست.</p>';
        }

        $tax_cols = [ 'gender' => 'جنسیت', 'board' => 'استان', 'age_category' => 'رده سنی', 'level' => 'سطح', ];
        ob_start();
        echo '<div class="sd-container">';
        echo '<div class="sd-header">لیست مسابقات</div>';
        echo '<table class="shop_table shop_table_responsive crm-competition-table striped"><thead><tr><th>#</th><th>عنوان</th>';
        foreach ( $tax_cols as $label ) {
            echo "<th>{$label}</th>";
        }
        echo '<th>قیمت</th><th>اقدام</th></tr></thead><tbody>';
        $i = 1;
        foreach ( $posts as $post ) :
            $pid = $post->ID;
            echo '<tr><td>' . ( $i++ ) . '</td><td>' . esc_html( get_the_title( $pid ) ) . '</td>';
            foreach ( $tax_cols as $slug => $label ) {
                $args  = [ 'fields' => 'names' ];
                if ( $slug === 'age_category' ) {
                    $args['parent'] = 0;
                }
                $terms = wp_get_post_terms( $pid, $slug, $args );
                echo '<td>' . ( $terms ? implode( ', ', $terms ) : '—' ) . '</td>';
            }
            $price = get_post_meta( $pid, 'price', true );
            echo '<td>' . wc_price( $price ) . '</td>';
            echo '<td><a href="' . esc_url( '/my-account/competition-details/?competition_id=' . $pid ) . '">جزئیات / ثبت‌نام</a></td></tr>';
        endforeach;
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    public function competition_details_shortcode($atts = []): string
    {
        $atts = shortcode_atts(['id' => 0], $atts);
        $cid = intval($atts['id'] ?: ($_GET['competition_id'] ?? 0));
        if (!$cid || get_post_type($cid) !== 'competition') {
            return '<p>مسابقه پیدا نشد.</p>';
        }

        if (!is_user_logged_in()) {
            return '<p style="text-align:center;color:#c00;">برای ثبت‌نام ابتدا وارد شوید.</p>';
        }
        $uid = get_current_user_id();
        $gender = UserMeta::gender_slug($uid);
        $status = (string)get_user_meta($uid, 'identity_verified_professional', true);
        $birth  = UserMeta::get($uid, 'birth_date', '');
        $age_slug = '';
        if ($birth) {
            $age_years = Date::age($birth);
            if ($age_years !== null) {
                $age_slug = AgeCategory::slug_from_age($age_years);
            }
        }
        if (!$gender || $status !== 'approved') {
            return '<p style="text-align:center;color:#c00;">جهت ثبت نام در مسابقات، ابتدا اطلاعات پایه را تکمیل و سپس در قسمت بارگذاری مستندات عکس و کارت ملی و شناسنامه را بارگذاری نمایید.</p>';
        }
        if (!$age_slug) {
            return '<p style="text-align:center;color:#c00;">برای ثبت‌نام، ابتدا تاریخ تولد خود را در اطلاعات پایه ثبت کنید تا ردهٔ سنی شما مشخص شود.</p>';
        }
        $gterms = wp_get_post_terms($cid, 'gender', ['fields' => 'slugs']);
        if ($gterms && !in_array($gender, $gterms, true)) {
            return '<p style="text-align:center;color:#c00;">این مسابقه با جنسیت شما سازگار نیست.</p>';
        }

        $terms   = wp_get_post_terms($cid, 'age_category');
        if (!is_array($terms) || (function_exists('is_wp_error') && is_wp_error($terms))) {
            $terms = [];
        }
        $ages    = [];
        $weights = [];
        $normalize_term = static function ($term, string $taxonomy) {
            if ($term instanceof WP_Term) {
                return $term;
            }
            if (is_object($term)) {
                $slug = (string) ($term->slug ?? '');
                if ($slug !== '') {
                    return $term;
                }
                $term_id = (int) ($term->term_id ?? 0);
            } elseif (is_array($term)) {
                if (!empty($term['slug'])) {
                    return (object) $term;
                }
                $term_id = (int) ($term['term_id'] ?? 0);
            } else {
                $term_id = (int) $term;
            }
            if ($term_id <= 0) {
                return null;
            }
            $fetched = get_term($term_id, $taxonomy);
            if ($fetched && (!function_exists('is_wp_error') || !is_wp_error($fetched))) {
                return $fetched;
            }
            if (is_object($term)) {
                return $term;
            }
            if (is_array($term)) {
                return (object) $term;
            }
            return null;
        };
        foreach ($terms as $t) {
            $term_obj = $normalize_term($t, 'age_category');
            if (!$term_obj) {
                continue;
            }
            $term_id   = (int) ($term_obj->term_id ?? 0);
            $parent_id = (int) ($term_obj->parent ?? 0);
            if ($parent_id) {
                $weights[$parent_id][] = $term_obj;
                if (!isset($ages[$parent_id])) {
                    $p = get_term($parent_id, 'age_category');
                    if ($p && (!function_exists('is_wp_error') || !is_wp_error($p))) {
                        $p_obj = $p instanceof WP_Term ? $p : (is_object($p) ? $p : (object) $p);
                        $parent_term_id = (int) ($p_obj->term_id ?? $parent_id);
                        if ($parent_term_id) {
                            $ages[$parent_term_id] = $p_obj;
                        }
                    }
                }
            } elseif ($term_id) {
                $ages[$term_id] = $term_obj;
            }
        }

        $eligible_age_ids = [];
        foreach ($ages as $term_id => $term_obj) {
            $slug = '';
            if ($term_obj instanceof WP_Term) {
                $slug = (string) ($term_obj->slug ?? '');
            } elseif (is_object($term_obj)) {
                $slug = (string) ($term_obj->slug ?? '');
            } elseif (is_array($term_obj)) {
                $slug = (string) ($term_obj['slug'] ?? '');
            }
            if ($slug === $age_slug) {
                $eligible_age_ids[] = $term_id;
            }
        }

        if (!$eligible_age_ids) {
            return '<p style="text-align:center;color:#c00;">این مسابقه با ردهٔ سنی شما سازگار نیست.</p>';
        }

        $eligible_keys = array_flip(array_unique($eligible_age_ids));
        $ages          = array_intersect_key($ages, $eligible_keys);
        $weights       = array_intersect_key($weights, $eligible_keys);

        $assignment_map = CompetitionTypeAssignments::get_map($cid);
        $competition_types = wp_get_post_terms($cid, 'competition_type');
        if (!is_array($competition_types) || (function_exists('is_wp_error') && is_wp_error($competition_types))) {
            $competition_types = [];
        }
        $competition_type_map = [];
        foreach ($competition_types as $term) {
            $term_obj = $normalize_term($term, 'competition_type');
            if (!$term_obj) {
                continue;
            }
            $term_id = (int) ($term_obj->term_id ?? 0);
            if ($term_id) {
                $competition_type_map[$term_id] = $term_obj;
            }
        }

        $types = $this->filter_types_for_age($cid, array_keys($ages), $competition_type_map);
        if ($assignment_map && !$types) {
            return '<p style="text-align:center;color:#c00;">نوع مسابقه‌ای برای ردهٔ سنی شما تعریف نشده است.</p>';
        }
        if (!$types) {
            $types = array_values($competition_type_map);
        }
        if (!$types) {
            return '<p style="text-align:center;color:#c00;">نوع مسابقه‌ای برای این مسابقه تعریف نشده است.</p>';
        }
        $type_names = array_values(array_filter(array_map(static function ($term) {
            if ($term instanceof WP_Term) {
                return (string) ($term->name ?? '');
            }
            if (is_object($term)) {
                return (string) ($term->name ?? '');
            }
            if (is_array($term)) {
                return (string) ($term['name'] ?? '');
            }
            return '';
        }, $types), static function ($name) {
            return $name !== '';
        }));

        $selected_type = count($types) === 1 ? $types[0] : null;

        $prod_id = (int) get_post_meta($cid, self::META_LINKED_PRODUCT, true);
        if (! $prod_id) {
            $prod_id = $this->sync_product($cid);
        }

        $fields     = self::detail_fields() + [
            'board'  => 'استان',
            'gender' => 'جنسیت',
            'level'  => 'سطح',
        ];
        $tax_fields = ['board', 'gender', 'level'];

        $selected_type_id = 0;
        $selected_type_name = '';
        if ($selected_type instanceof WP_Term) {
            $selected_type_id   = (int) ($selected_type->term_id ?? 0);
            $selected_type_name = (string) ($selected_type->name ?? '');
        } elseif (is_object($selected_type)) {
            $selected_type_id   = (int) ($selected_type->term_id ?? 0);
            $selected_type_name = (string) ($selected_type->name ?? '');
        } elseif (is_array($selected_type)) {
            $selected_type_id   = (int) ($selected_type['term_id'] ?? 0);
            $selected_type_name = (string) ($selected_type['name'] ?? '');
        }

        $type_display = $selected_type_name;
        if ($type_display === '' && $type_names) {
            $type_display = implode('، ', $type_names);
        }

        ob_start();
        ?>
        <form method="get" action="<?php echo esc_url(wc_get_cart_url()); ?>" class="crm-competition-form">
            <input type="hidden" name="add-to-cart" value="<?php echo $prod_id; ?>">
            <?php if ($selected_type_id) : ?>
                <input type="hidden" name="competition_type_term" value="<?php echo $selected_type_id; ?>">
            <?php endif; ?>
            <div class="crm-single-course">
                <h3><?php echo esc_html(get_the_title($cid)); ?></h3>
                <table class="striped">
                    <tbody>
                    <?php foreach ($fields as $key => $label) :
                        if (in_array($key, $tax_fields, true)) {
                            $terms = get_the_terms($cid, $key);
                            $val   = $terms && ! is_wp_error($terms) ? join(', ', wp_list_pluck($terms, 'name')) : '';
                        } else {
                            $val = get_post_meta($cid, $key, true);
                            if ($key === 'price') {
                                $val = wc_price((float) $val);
                            }
                        }
                    ?>
                        <tr>
                            <th><?php echo esc_html($label); ?></th>
                            <td><?php echo $val ? $val : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($conditions = get_post_meta($cid, 'special_conditions', true)) : ?>
                        <tr>
                            <th>شرایط خاص</th>
                            <td><?php echo nl2br(esc_html($conditions)); ?></td>
                        </tr>
                    <?php endif; ?>
                    <?php if ($type_display !== '') : ?>
                        <tr>
                            <th>نوع مسابقه</th>
                            <td><?php echo esc_html($type_display); ?></td>
                        </tr>
                    <?php endif; ?>
                    <tr>
                        <th>دسته وزنی</th>
                        <td>
                            <select name="weight_class_term" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ($ages as $aid => $age) :
                                    if (!empty($weights[$aid])) : ?>
                                        <optgroup label="<?php echo esc_attr($age->name); ?>">
                                            <?php foreach ($weights[$aid] as $t) : ?>
                                                <option value="<?php echo $t->term_id; ?>"><?php echo esc_html($t->name); ?></option>
                                            <?php endforeach; ?>
                                        </optgroup>
                                    <?php endif;
                                endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    </tbody>
                </table>
                <p style="text-align:center">
                    <button type="submit" class="crm-buy-btn">پرداخت و ثبت‌نام</button>
                </p>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    public function user_competitions_shortcode(): string
    {
        if (!is_user_logged_in()) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ مسابقات ابتدا وارد شوید.</p>';
        }

        $user_id = get_current_user_id();
        $rows = [];

        $orders = wc_get_orders(['customer_id' => $user_id, 'limit' => -1, 'orderby' => 'date', 'order' => 'DESC', 'status' => ['completed', 'processing', 'pending', 'on-hold'],]);

        foreach ($orders as $order) {
            foreach ($order->get_items() as $item) {
                $comp_id = (int)get_post_meta($item->get_product_id(), self::META_LINKED_COMPETITION, true);
                if (!$comp_id || get_post_type($comp_id) !== 'competition') {
                    continue;
                }

                $rows[] = ['competition_id' => $comp_id, 'order_id' => $order->get_id(), 'order_date' => $order->get_date_created()->date_i18n('Y/m/d'), 'amount' => $item->get_total(), 'status' => wc_get_order_status_name($order->get_status()), 'weight_class' => $item->get_meta('دسته وزنی', true), 'age_category' => $item->get_meta('رده سنی', true),];
            }
        }

        if (!$rows) {
            return '<p>تا کنون در مسابقه‌ای شرکت نکرده‌اید.</p>';
        }

        ob_start();
        ?>
        <div class="crm-course-wrap">
            <div class="crm-course-title">لیست مسابقات شما</div>
            <table class="crm-competition-table striped">
                <thead>
                <tr>
                    <th>#</th>
                    <th>نام مسابقه</th>
                    <th>کد مسابقه</th>
                    <th>دسته وزنی</th>
                    <th>رده سنی</th>
                    <th>شماره سفارش</th>
                    <th>تاریخ سفارش</th>
                    <th>مبلغ پرداختی</th>
                    <th>وضعیت سفارش</th>
                    <th>اقدام</th>
                </tr>
                </thead>
                <tbody>
                <?php $i = 1;
                foreach ($rows as $r) : $cid = $r['competition_id']; ?>
                    <tr>
                        <td><?php echo $i++; ?></td>
                        <td><?php echo esc_html(get_the_title($cid)); ?></td>
                        <td><?php echo esc_html(get_post_meta($cid, 'competition_code', true)); ?></td>
                        <td><?php echo esc_html($r['weight_class'] ?: '—'); ?></td>
                        <td><?php echo esc_html($r['age_category'] ?: '—'); ?></td>
                        <td>#<?php echo $r['order_id']; ?></td>
                        <td><?php echo esc_html($r['order_date']); ?></td>
                        <td><?php echo wc_price($r['amount']); ?></td>
                        <td><?php echo esc_html($r['status']); ?></td>
                        <td><a href="<?php echo esc_url('/my-account/competition-details/?competition_id=' . $cid); ?>">جزئیات</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
