<?php

namespace IMAOCustom\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\Date;
use IMAOCustom\Services\CompetitionCardTemplates;
use WC_Order;
use WC_Order_Item_Product;
use WP_Post;

class CompetitionCards {
    private const META_LINKED_COMPETITION = '_linked_post_id';
    private const META_CONFIRMED = '_imao_weigh_in_confirmed';
    private const META_WEIGHT = '_imao_actual_weight';
    private const META_INSURANCE_DATE = '_imao_insurance_date';
    private const META_WEIGHED_AT = '_imao_weighed_at';
    private const META_WEIGHED_BY = '_imao_weighed_by';
    private const ITEM_META_SPORTS_INSURANCE_EXPIRY = 'پایان اعتبار بیمه ورزشی';
    private const ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY = 'پایان اعتبار کارت عضویت فدراسیون';
    private const ITEM_META_WEIGHT_CLASS_AUDIT = '_imao_weight_class_audit';
    private const ITEM_META_DORMITORY = '_imao_dormitory_requested';

    public function register(): void {
        add_action( 'admin_post_imao_dormitory_export', [ $this, 'export_dormitory' ] );
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_competition', [ $this, 'save_weigh_ins' ], 20, 2 );
        add_action( 'admin_post_imao_save_competition_weigh_ins', [ $this, 'handle_weigh_ins_save' ] );
        add_action( 'wp_ajax_imao_weigh_ins_datatable', [ $this, 'ajax_weigh_ins_datatable' ] );
        add_action( 'admin_post_imao_competition_card', [ $this, 'render_card' ] );
        add_action( 'template_redirect', [ $this, 'render_frontend_card' ], 0 );
        add_action( 'admin_post_imao_verify_competition_card', [ $this, 'verify_card' ] );
        add_action( 'admin_post_nopriv_imao_verify_competition_card', [ $this, 'verify_card' ] );
    }

    public function add_meta_box(): void {
        add_meta_box( 'imao_competition_cards', 'وزن‌کشی و صدور کارت مسابقه', [ $this, 'render_meta_box' ], 'competition', 'normal', 'default' );
    }

    public function render_meta_box( WP_Post $post ): void {
        if ( empty( $post->ID ) || in_array( $post->post_status, [ 'auto-draft' ], true ) ) {
            echo '<p style="color:#666">پس از ذخیره مسابقه، صفحه وزن‌کشی در دسترس خواهد بود.</p>';
            return;
        }
        if ( ! current_user_can( 'edit_post', (int) $post->ID ) ) {
            echo '<p style="color:#a00">دسترسی مجاز نیست.</p>';
            return;
        }
        echo '<p>برای افزایش سرعت صفحه و جستجوی بهتر، وزن‌کشی در صفحه جداگانه با صفحه‌بندی و جستجوی سمت سرور مدیریت می‌شود.</p>';
        echo '<p><a class="button button-primary" href="' . esc_url( self::weigh_ins_page_url( (int) $post->ID ) ) . '">مدیریت وزن‌کشی و صدور کارت</a></p>';
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            null,
            'وزن‌کشی مسابقه',
            'وزن‌کشی مسابقه',
            'edit_posts',
            'crm-competition-weigh-ins',
            [ $this, 'render_weigh_ins_page' ]
        );
    }

    public static function weigh_ins_page_url( int $competition_id ): string {
        return add_query_arg(
            [
                'page'           => 'crm-competition-weigh-ins',
                'competition_id' => $competition_id,
            ],
            admin_url( 'admin.php' )
        );
    }

    public function enqueue_admin_assets(): void {
        $is_page = isset( $_GET['page'] ) && (string) $_GET['page'] === 'crm-competition-weigh-ins';
        if ( ! $is_page ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) );
        wp_enqueue_style( 'imao-jdp', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-dt', $url . 'assets/css/jquery.dataTables.min.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-jdp', $url . 'assets/js/jalalidatepicker.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-dt', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], '1.0.0', true );
    }

    public function render_weigh_ins_page(): void {
        $competition_id = (int) ( $_GET['competition_id'] ?? 0 );
        if ( ! $competition_id || get_post_type( $competition_id ) !== 'competition' ) {
            wp_die( 'مسابقه پیدا نشد.', 'مسابقه پیدا نشد', [ 'response' => 404 ] );
        }
        if ( ! current_user_can( 'edit_post', $competition_id ) ) {
            wp_die( 'دسترسی مجاز نیست.', 'دسترسی مجاز نیست', [ 'response' => 403 ] );
        }
        echo '<p>کارت پس از پرداخت موفق، بلافاصله در پنل ورزشکار قابل دانلود و چاپ است. تأیید وزن‌کشی فقط برای ورود به جدول حذفی مسابقه لازم است.</p>';
        $export_url = wp_nonce_url( add_query_arg( [ 'action' => 'imao_dormitory_export', 'competition_id' => $competition_id ], admin_url( 'admin-post.php' ) ), 'imao_dormitory_export_' . $competition_id );
        $save_url = admin_url( 'admin-post.php' );
        $ajax_nonce = wp_create_nonce( 'imao_weigh_ins_table_' . $competition_id );
        echo '<div class="wrap"><h1>وزن‌کشی و صدور کارت مسابقه: ' . esc_html( get_the_title( $competition_id ) ) . '</h1>';
        if ( isset( $_GET['weigh_saved'] ) && (string) $_GET['weigh_saved'] === '1' ) {
            echo '<div class="notice notice-success is-dismissible"><p>تغییرات وزن‌کشی ذخیره شد.</p></div>';
        }
        echo '<p><a class="button" href="' . esc_url( get_edit_post_link( $competition_id, '' ) ) . '">بازگشت به ویرایش مسابقه</a> <a class="button" href="' . esc_url( $export_url ) . '">خروجی اکسل اسامی متقاضیان خوابگاه</a></p>';
        echo '<form method="post" action="' . esc_url( $save_url ) . '" id="imao-weigh-in-form">';
        echo '<input type="hidden" name="action" value="imao_save_competition_weigh_ins">';
        echo '<input type="hidden" name="competition_id" value="' . (int) $competition_id . '">';
        wp_nonce_field( 'imao_save_competition_weigh_ins_' . $competition_id, 'imao_competition_weigh_ins_nonce' );
        echo '<p><button type="submit" class="button button-primary">ذخیره ردیف‌های نمایش داده شده</button> <span class="description">در جدول سمت سرور، فقط ردیف‌های صفحه فعلی ارسال و ذخیره می‌شوند؛ ردیف‌های صفحات دیگر دست‌نخورده می‌مانند.</span></p>';
        echo '<div style="overflow:auto"><table id="imao-weigh-in-table" class="wp-list-table widefat striped" data-ajax-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-competition-id="' . (int) $competition_id . '" data-nonce="' . esc_attr( $ajax_nonce ) . '"><thead><tr><th>ردیف</th><th>نام ورزشکار</th><th>کد ملی</th><th>سفارش</th><th>پایان اعتبار بیمه ورزشی</th><th>پایان اعتبار کارت عضویت فدراسیون</th><th>دسته ثبت‌نامی</th><th>وزن واقعی</th><th>دسته حضور پس از وزن‌کشی</th><th>خوابگاه</th><th>تأیید وزن‌کشی</th><th>کارت</th><th>سوابق دسته</th></tr></thead></table></div>';
        echo '<p><button type="submit" class="button button-primary">ذخیره ردیف‌های نمایش داده شده</button></p></form>';
        echo '<p><strong>توجه:</strong> دسته حضور، روی همین ثبت‌نام ذخیره می‌شود و در جدول حذفی، کارت و رده‌بندی‌های بعدی استفاده خواهد شد. برای اعمال تغییرات، دکمه «ذخیره ردیف‌های نمایش داده شده» را بزنید.</p>';
        echo '</div>';
        wp_add_inline_script( 'imao-dt', $this->weigh_ins_datatable_script() );
        wp_add_inline_script( 'imao-jdp', 'jQuery(function(){if(window.jalaliDatepicker){jalaliDatepicker.startWatch();}});' );
    }

    public static function normalize_search( string $value ): string {
        return strtolower( trim( strtr( str_replace( [ 'ي', 'ك' ], [ 'ی', 'ک' ], $value ), array_combine( preg_split( '//u', '۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', -1, PREG_SPLIT_NO_EMPTY ), str_split( '01234567890123456789' ) ) ) ) );
    }

    private function weigh_ins_datatable_script(): string {
        return <<<'JS'
jQuery(function($){
    var $table = $('#imao-weigh-in-table');
    if (!$table.length || !$.fn.DataTable) { return; }
    var dirty = false;
    $(document).on('change input', '#imao-weigh-in-form :input', function(){
        if (this.name && this.name.indexOf('imao_weigh_ins[') === 0) { dirty = true; }
    });
    $('#imao-weigh-in-form').on('submit', function(){ dirty = false; });
    var dt = $table.DataTable({
        processing: true,
        serverSide: true,
        deferRender: true,
        stateSave: true,
        searchDelay: 450,
        pageLength: 25,
        lengthMenu: [[10,25,50,100],[10,25,50,100]],
        order: [],
        language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json' },
        ajax: {
            url: $table.data('ajax-url'),
            type: 'POST',
            data: function(data) {
                data.action = 'imao_weigh_ins_datatable';
                data.competition_id = $table.data('competition-id');
                data.nonce = $table.data('nonce');
            }
        },
        columns: [
            {data:'row', orderable:false},
            {data:'name', orderable:false},
            {data:'national_id', orderable:false},
            {data:'order', orderable:false},
            {data:'sports_insurance_expiry', orderable:false},
            {data:'federation_membership_expiry', orderable:false},
            {data:'registered_weight', orderable:false},
            {data:'actual_weight', orderable:false},
            {data:'attendance_weight_class', orderable:false},
            {data:'dormitory', orderable:false},
            {data:'confirmed', orderable:false},
            {data:'card', orderable:false},
            {data:'audit', orderable:false}
        ],
        preDrawCallback: function() {
            if (!dirty) { return true; }
            return window.confirm('تغییرات وزن‌کشی ذخیره نشده است. بدون ذخیره ادامه می‌دهید؟');
        },
        drawCallback: function() {
            dirty = false;
            if (window.jalaliDatepicker) { jalaliDatepicker.startWatch(); }
        }
    });
});
JS;
    }

    public function ajax_weigh_ins_datatable(): void {
        $competition_id = (int) ( $_POST['competition_id'] ?? 0 );
        if ( ! $competition_id || get_post_type( $competition_id ) !== 'competition' || ! current_user_can( 'edit_post', $competition_id ) ) {
            wp_send_json_error( [ 'message' => 'دسترسی مجاز نیست.' ], 403 );
        }
        check_ajax_referer( 'imao_weigh_ins_table_' . $competition_id, 'nonce' );
        $draw = max( 0, (int) ( $_POST['draw'] ?? 0 ) );
        $start = max( 0, (int) ( $_POST['start'] ?? 0 ) );
        $length = (int) ( $_POST['length'] ?? 25 );
        $length = $length > 0 ? min( 100, $length ) : 25;
        $search = '';
        if ( isset( $_POST['search'] ) && is_array( $_POST['search'] ) ) {
            $search = sanitize_text_field( wp_unslash( (string) ( $_POST['search']['value'] ?? '' ) ) );
        }

        $entries = $this->paid_entries( $competition_id );
        $filtered = $this->filter_weigh_in_entries( $entries, $search );
        $weight_options = $this->competition_weight_options( $competition_id );
        $page = array_slice( $filtered, $start, $length );
        $data = [];
        foreach ( $page as $index => $entry ) {
            $data[] = $this->weigh_in_datatable_row( $entry, $competition_id, $weight_options, $start + $index + 1 );
        }
        wp_send_json( [
            'draw'            => $draw,
            'recordsTotal'    => count( $entries ),
            'recordsFiltered' => count( $filtered ),
            'data'            => $data,
        ] );
    }

    /** @param array<int,array{order:WC_Order,item:WC_Order_Item_Product}> $entries */
    private function filter_weigh_in_entries( array $entries, string $query ): array {
        if ( $query === '' ) {
            return $entries;
        }
        $needle = self::normalize_search( $query );
        return array_values( array_filter( $entries, function ( array $entry ) use ( $needle ): bool {
            $user_id = (int) $entry['order']->get_customer_id();
            $user = get_userdata( $user_id );
            $mobile = (string) (
                get_user_meta( $user_id, 'billing_phone', true )
                ?: get_user_meta( $user_id, 'mobile', true )
                ?: get_user_meta( $user_id, 'mobile_number', true )
                ?: get_user_meta( $user_id, 'phone', true )
            );
            $dormitory = $entry['item']->get_meta( self::ITEM_META_DORMITORY, true ) === 'yes';
            $haystack = ( $user ? $this->user_display_name( $user, $user_id ) : '' )
                . ' ' . get_user_meta( $user_id, 'national_id', true )
                . ' ' . $mobile
                . ' ' . $entry['order']->get_id()
                . ' ' . $entry['item']->get_meta( 'دسته وزنی', true )
                . ' ' . ( $dormitory ? 'خوابگاه دارد dormitory yes' : 'بدون خوابگاه dormitory no' );
            return strpos( self::normalize_search( $haystack ), $needle ) !== false;
        } ) );
    }

    /** @param array{order:WC_Order,item:WC_Order_Item_Product} $entry @param array<int,array{id:int,label:string}> $weight_options */
    private function weigh_in_datatable_row( array $entry, int $competition_id, array $weight_options, int $row_number ): array {
        $item = $entry['item'];
        $order = $entry['order'];
        $item_id = (int) $item->get_id();
        $user_id = (int) $order->get_customer_id();
        $user = get_userdata( $user_id );
        $display_name = $user ? $this->user_display_name( $user, $user_id ) : '#' . $user_id;
        $national_id = (string) get_user_meta( $user_id, 'national_id', true );
        $registered_weight = (string) $item->get_meta( 'دسته وزنی', true );
        $attendance_weight_id = (int) $item->get_meta( '_imao_weight_class_term', true );
        $dormitory = $item->get_meta( self::ITEM_META_DORMITORY, true ) === 'yes';
        $audit = (array) $item->get_meta( self::ITEM_META_WEIGHT_CLASS_AUDIT, true );
        $select = '<select name="imao_weigh_ins[' . $item_id . '][attendance_weight_class]" style="min-width:180px"><option value="">— بدون تغییر —</option>';
        foreach ( $weight_options as $option ) {
            $select .= '<option value="' . (int) $option['id'] . '" ' . selected( $attendance_weight_id, $option['id'], false ) . '>' . esc_html( $option['label'] ) . '</option>';
        }
        $select .= '</select>';
        return [
            'row'                          => (string) $row_number,
            'name'                         => esc_html( $display_name ),
            'national_id'                  => esc_html( $national_id ?: '—' ),
            'order'                        => '#' . (int) $order->get_id(),
            'sports_insurance_expiry'      => '<input type="text" class="crm-date" data-jdp data-jdp-only-date inputmode="numeric" placeholder="۱۴۰۵/۰۵/۱۷" name="imao_weigh_ins[' . $item_id . '][sports_insurance_expiry]" value="' . esc_attr( (string) $item->get_meta( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, true ) ) . '" style="width:130px">',
            'federation_membership_expiry' => '<input type="text" class="crm-date" data-jdp data-jdp-only-date inputmode="numeric" placeholder="۱۴۰۵/۰۵/۱۷" name="imao_weigh_ins[' . $item_id . '][federation_membership_expiry]" value="' . esc_attr( (string) $item->get_meta( self::ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY, true ) ) . '" style="width:130px">',
            'registered_weight'            => esc_html( $registered_weight ?: '—' ),
            'actual_weight'                => '<input type="number" min="1" max="300" step="0.01" name="imao_weigh_ins[' . $item_id . '][weight]" value="' . esc_attr( (string) $item->get_meta( self::META_WEIGHT, true ) ) . '" style="width:100px">',
            'attendance_weight_class'      => $select,
            'dormitory'                    => '<label><input type="checkbox" name="imao_weigh_ins[' . $item_id . '][dormitory]" value="1" ' . checked( true, $dormitory, false ) . '> دارد</label>',
            'confirmed'                    => '<label><input type="checkbox" name="imao_weigh_ins[' . $item_id . '][confirmed]" value="1" ' . checked( 'yes', $item->get_meta( self::META_CONFIRMED, true ), false ) . '> تأیید شد</label>',
            'card'                         => self::item_is_ready( $order, $item ) ? '<a class="button" target="_blank" href="' . esc_url( self::card_url( (int) $order->get_id(), $item_id ) ) . '">مشاهده کارت</a>' : 'در انتظار',
            'audit'                        => $audit ? '<details><summary>' . count( $audit ) . ' تغییر</summary><ol style="margin:6px 18px 0 0">' . $this->render_weight_audit( $audit ) . '</ol></details>' : '—',
        ];
    }

    /** One row per paid registration; export always includes all saved dormitory requests. */
    public function dormitory_rows( int $competition_id ): array {
        $rows = [];
        foreach ( $this->paid_entries( $competition_id ) as $entry ) {
            if ( $entry['item']->get_meta( self::ITEM_META_DORMITORY, true ) !== 'yes' ) continue;
            $user_id = (int) $entry['order']->get_customer_id();
            $user = get_userdata( $user_id );
            $rows[] = [ (string) ( count( $rows ) + 1 ), $user ? $this->user_display_name( $user, $user_id ) : 'کاربر حذف‌شده',
                (string) get_user_meta( $user_id, 'national_id', true ),
                (string) ( get_user_meta( $user_id, 'billing_phone', true ) ?: get_user_meta( $user_id, 'mobile', true ) ),
                (string) $entry['order']->get_id(), (string) $entry['item']->get_meta( 'دسته وزنی', true ) ];
        }
        return $rows;
    }

    public function export_dormitory(): void {
        $competition_id = (int) ( $_GET['competition_id'] ?? 0 );
        if ( get_post_type( $competition_id ) !== 'competition' || ! current_user_can( 'edit_post', $competition_id ) ) wp_die( 'دسترسی مجاز نیست.', '', [ 'response' => 403 ] );
        check_admin_referer( 'imao_dormitory_export_' . $competition_id );
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft( true );
        $rows = array_merge( [ [ 'ردیف', 'نام ورزشکار', 'کد ملی', 'موبایل', 'سفارش', 'دسته وزنی' ] ], $this->dormitory_rows( $competition_id ) );
        foreach ( $rows as $row_index => $row ) {
            foreach ( $row as $column => $value ) {
                $sheet->setCellValueExplicit( \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex( $column + 1 ) . ( $row_index + 1 ), $value, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING );
            }
        }
        foreach ( range( 'A', 'F' ) as $column ) $sheet->getColumnDimension( $column )->setAutoSize( true );
        $sheet->getStyle( 'A1:F1' )->getFont()->setBold( true );
        nocache_headers();
        header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
        header( 'Content-Disposition: attachment; filename="dormitory-' . $competition_id . '.xlsx"' );
        ( new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $spreadsheet ) )->save( 'php://output' );
        exit;
    }

    public function save_weigh_ins( int $competition_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $competition_id ) ) return;
        $nonce = $_POST['imao_competition_weigh_ins_nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'imao_save_competition_weigh_ins_' . $competition_id ) ) return;
        $submitted = isset( $_POST['imao_weigh_ins'] ) && is_array( $_POST['imao_weigh_ins'] ) ? wp_unslash( $_POST['imao_weigh_ins'] ) : [];
        $this->persist_weigh_ins( $competition_id, $submitted );
    }

    public function handle_weigh_ins_save(): void {
        $competition_id = (int) ( $_POST['competition_id'] ?? 0 );
        if ( ! $competition_id || get_post_type( $competition_id ) !== 'competition' || ! current_user_can( 'edit_post', $competition_id ) ) {
            wp_die( 'دسترسی مجاز نیست.', 'دسترسی مجاز نیست', [ 'response' => 403 ] );
        }
        $nonce = $_POST['imao_competition_weigh_ins_nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'imao_save_competition_weigh_ins_' . $competition_id ) ) {
            wp_die( 'درخواست معتبر نیست.', 'درخواست معتبر نیست', [ 'response' => 403 ] );
        }
        $submitted = isset( $_POST['imao_weigh_ins'] ) && is_array( $_POST['imao_weigh_ins'] ) ? wp_unslash( $_POST['imao_weigh_ins'] ) : [];
        $this->persist_weigh_ins( $competition_id, $submitted );
        wp_safe_redirect( add_query_arg( 'weigh_saved', '1', self::weigh_ins_page_url( $competition_id ) ) );
        exit;
    }

    /** @param array<int|string,mixed> $submitted */
    private function persist_weigh_ins( int $competition_id, array $submitted ): void {
        foreach ( $this->paid_entries( $competition_id ) as $entry ) {
            $item = $entry['item'];
            $item_id = (int) $item->get_id();
            if ( ! isset( $submitted[ $item_id ] ) || ! is_array( $submitted[ $item_id ] ) ) {
                continue;
            }
            $row = isset( $submitted[ $item_id ] ) && is_array( $submitted[ $item_id ] ) ? $submitted[ $item_id ] : [];
            $weight = max( 0.0, min( 300.0, (float) ( $row['weight'] ?? 0 ) ) );
            $sports_insurance_expiry = $this->sanitize_admin_date( $row['sports_insurance_expiry'] ?? '' );
            $federation_membership_expiry = $this->sanitize_admin_date( $row['federation_membership_expiry'] ?? '' );
            $confirmed = ! empty( $row['confirmed'] );
            $dormitory = ! empty( $row['dormitory'] );
            $this->save_attendance_weight_class( $entry['order'], $item, $competition_id, $row['attendance_weight_class'] ?? '' );
            $item->update_meta_data( self::META_WEIGHT, $weight > 0 ? $weight : '' );
            $item->update_meta_data( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, $sports_insurance_expiry );
            $item->update_meta_data( self::ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY, $federation_membership_expiry );
            $item->update_meta_data( self::ITEM_META_DORMITORY, $dormitory ? 'yes' : 'no' );
            $item->update_meta_data( self::META_CONFIRMED, $confirmed ? 'yes' : 'no' );
            if ( $confirmed ) {
                $item->update_meta_data( self::META_WEIGHED_AT, current_time( 'mysql' ) );
                $item->update_meta_data( self::META_WEIGHED_BY, get_current_user_id() );
            } else {
                $item->delete_meta_data( self::META_WEIGHED_AT );
                $item->delete_meta_data( self::META_WEIGHED_BY );
                CompetitionBrackets::remove_item_from_snapshot( $competition_id, (int) $entry['order']->get_id(), $item_id );
            }
            $item->save();
        }
    }

    private function user_display_name( $user, int $user_id ): string {
        $first = (string) get_user_meta( $user_id, 'first_name_fa', true );
        $last = (string) get_user_meta( $user_id, 'last_name_fa', true );
        return trim( $first . ' ' . $last ) ?: (string) $user->display_name;
    }

    /** @return array<int,array{id:int,label:string}> */
    private function competition_weight_options( int $competition_id ): array {
        $terms = get_terms( [ 'taxonomy' => 'age_category', 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC' ] );
        $selected = wp_get_post_terms( $competition_id, 'age_category' );
        $parents = [];
        foreach ( (array) $selected as $term ) {
            $parent = (int) ( $term->parent ?? 0 );
            if ( $parent > 0 ) $parents[ $parent ] = true;
            elseif ( isset( $term->term_id ) ) $parents[ (int) $term->term_id ] = true;
        }
        $options = [];
        foreach ( (array) $terms as $term ) {
            $parent = (int) ( $term->parent ?? 0 );
            if ( ! isset( $term->term_id ) || ! $parent || ! isset( $parents[ $parent ] ) ) continue;
            $parent_term = get_term( $parent, 'age_category' );
            $label = ( $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->name . ' — ' : '' ) . $term->name;
            $options[] = [ 'id' => (int) $term->term_id, 'label' => (string) $label ];
        }
        return $options;
    }

    private function save_attendance_weight_class( $order, $item, int $competition_id, $submitted_id ): void {
        $submitted_id = absint( $submitted_id );
        if ( ! $submitted_id ) return;
        $options = array_column( $this->competition_weight_options( $competition_id ), null, 'id' );
        if ( ! isset( $options[ $submitted_id ] ) ) return;
        $old_id = (int) $item->get_meta( '_imao_weight_class_term', true );
        $old_name = (string) $item->get_meta( 'دسته وزنی', true );
        $new_name = (string) $options[ $submitted_id ]['label'];
        if ( $old_id === $submitted_id && $old_name === $new_name ) return;
        $audit = (array) $item->get_meta( self::ITEM_META_WEIGHT_CLASS_AUDIT, true );
        $audit[] = [
            'at' => current_time( 'mysql' ),
            'user_id' => get_current_user_id(),
            'from_id' => $old_id,
            'from' => $old_name,
            'to_id' => $submitted_id,
            'to' => $new_name,
            'competition_id' => $competition_id,
        ];
        $item->update_meta_data( self::ITEM_META_WEIGHT_CLASS_AUDIT, $audit );
        $item->update_meta_data( '_imao_weight_class_term', $submitted_id );
        $item->update_meta_data( 'دسته وزنی', $new_name );
        if ( is_object( $order ) && method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note( sprintf( 'دسته حضور مسابقه #%d برای قلم #%d تغییر کرد: %s ← %s (کاربر مدیر #%d).', $competition_id, (int) $item->get_id(), $old_name ?: '—', $new_name, get_current_user_id() ) );
        }
    }

    private function render_weight_audit( array $audit ): string {
        $html = '';
        foreach ( array_reverse( $audit ) as $row ) {
            $html .= '<li>' . esc_html( (string) ( $row['at'] ?? '' ) . ' — ' . (string) ( $row['from'] ?? '—' ) . ' ← ' . (string) ( $row['to'] ?? '—' ) . ' — مدیر #' . (string) ( $row['user_id'] ?? '' ) ) . '</li>';
        }
        return $html;
    }

    private function sanitize_admin_date( $value ): string {
        $value = is_scalar( $value ) ? (string) $value : '';
        $value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
        return preg_match( '/^[0-9۰-۹]{4}\/[0-9۰-۹]{1,2}\/[0-9۰-۹]{1,2}$/u', $value ) ? $value : '';
    }

    public static function item_is_ready( $order, $item ): bool {
        if ( ! is_object( $order ) || ! is_object( $item ) ) return false;
        return method_exists( $order, 'has_status' ) && $order->has_status( [ 'processing', 'completed' ] );
    }

    /** A confirmed, positive weigh-in is required for a competition bracket, not a card. */
    public static function item_is_weighed_in( $item ): bool {
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) return false;
        $confirmed = method_exists( $item, 'get_meta' ) && $item->get_meta( self::META_CONFIRMED, true ) === 'yes';
        $weight = method_exists( $item, 'get_meta' ) ? (float) $item->get_meta( self::META_WEIGHT, true ) : 0.0;
        return $confirmed && $weight > 0;
    }

    /** Brackets are built from the admin confirmation checkbox; actual weight can be filled separately. */
    public static function item_is_bracket_eligible( $item ): bool {
        if ( ! is_object( $item ) || ! method_exists( $item, 'get_meta' ) ) return false;
        return $item->get_meta( self::META_CONFIRMED, true ) === 'yes';
    }

    public static function card_url( int $order_id, int $item_id ): string {
        return add_query_arg(
            [
                'imao_competition_card' => 1,
                'imao_card_endpoint'   => 1,
                'order_id'             => $order_id,
                'item_id'              => $item_id,
                'token'                => self::card_access_token( $order_id, $item_id ),
            ],
            home_url( '/competition-card/' )
        );
    }

    /** Supports the dedicated route and previously printed card links. */
    public function render_frontend_card(): void {
        if ( (int) ( $_GET['imao_competition_card'] ?? 0 ) !== 1 ) return;
        $this->render_card();
    }

    public function render_card(): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        if ( ! is_user_logged_in() ) auth_redirect();
        $order_id = (int) ( $_GET['order_id'] ?? 0 );
        $item_id = (int) ( $_GET['item_id'] ?? 0 );
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        if ( ! hash_equals( self::card_access_token( $order_id, $item_id ), $token ) ) {
            wp_die( 'لینک کارت معتبر نیست. لطفاً از پنل کاربری دوباره تلاش کنید.', 'لینک نامعتبر', [ 'response' => 403 ] );
        }
        $order = wc_get_order( $order_id );
        $item = $order && method_exists( $order, 'get_item' ) ? $order->get_item( $item_id ) : null;
        $allowed = $order && ( (int) $order->get_customer_id() === get_current_user_id() || current_user_can( 'manage_options' ) );
        if ( ! $allowed || ! $item instanceof WC_Order_Item_Product || ! self::item_is_ready( $order, $item ) ) {
            wp_die( 'کارت هنوز صادر نشده یا دسترسی به آن مجاز نیست.', 'دسترسی غیرمجاز', [ 'response' => 403 ] );
        }
        $competition_id = (int) get_post_meta( $item->get_product_id(), self::META_LINKED_COMPETITION, true );
        if ( ! $competition_id || get_post_type( $competition_id ) !== 'competition' ) wp_die( 'مسابقه یافت نشد.', 'مسابقه یافت نشد', [ 'response' => 404 ] );
        $data = $this->card_data( $order, $item, $competition_id );
        nocache_headers();
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        $template_id = CompetitionCardTemplates::selected_template_id( $competition_id );
        if ( $template_id > 0 ) {
            echo CompetitionCardTemplates::render_document( $template_id, $data );
            exit;
        }
        echo $this->card_document( $data );
        exit;
    }

    public function verify_card(): void {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        if ( ! defined( 'DONOTCACHEOBJECT' ) ) define( 'DONOTCACHEOBJECT', true );
        $order_id = (int) ( $_GET['order_id'] ?? 0 );
        $item_id = (int) ( $_GET['item_id'] ?? 0 );
        $token = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) );
        $order = $order_id > 0 ? wc_get_order( $order_id ) : null;
        $item = $order && method_exists( $order, 'get_item' ) ? $order->get_item( $item_id ) : null;
        $valid = $order
            && $item instanceof WC_Order_Item_Product
            && self::item_is_ready( $order, $item )
            && hash_equals( self::verification_token( $order_id, $item_id, (int) $order->get_customer_id() ), $token );

        $competition_id = $valid ? (int) get_post_meta( $item->get_product_id(), self::META_LINKED_COMPETITION, true ) : 0;
        $name = '';
        if ( $valid ) {
            $user_id = (int) $order->get_customer_id();
            $first = (string) get_user_meta( $user_id, 'first_name_fa', true );
            $last = (string) get_user_meta( $user_id, 'last_name_fa', true );
            $user = get_userdata( $user_id );
            $name = trim( $first . ' ' . $last ) ?: ( $user ? $user->display_name : '' );
        }

        nocache_headers();
        status_header( $valid ? 200 : 404 );
        header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
        ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>استعلام کارت مسابقه</title><style>body{margin:0;padding:24px;background:#f4f5f7;color:#17134b;font-family:Tahoma,Arial,sans-serif}.result{max-width:520px;margin:10vh auto;padding:32px;border-radius:16px;background:#fff;box-shadow:0 10px 35px #0001;text-align:center}.status{font-size:22px;font-weight:700;color:<?php echo $valid ? '#16833b' : '#b42318'; ?>}.details{margin-top:22px;line-height:2}</style></head><body><main class="result"><div class="status"><?php echo $valid ? '✓ کارت مسابقه معتبر است' : 'کارت مسابقه معتبر نیست یا هنوز صادر نشده است'; ?></div><?php if ( $valid ) : ?><div class="details"><div><?php echo esc_html( $name ); ?></div><div><?php echo esc_html( get_the_title( $competition_id ) ); ?></div><?php if ( self::item_is_weighed_in( $item ) ) : ?><div>وزن تأییدشده: <?php echo esc_html( (string) $item->get_meta( self::META_WEIGHT, true ) ); ?> KG</div><?php endif; ?></div><?php endif; ?></main></body></html>
        <?php
        exit;
    }

    /** @return array<string,string> */
    private function card_data( WC_Order $order, WC_Order_Item_Product $item, int $competition_id ): array {
        $user_id = (int) $order->get_customer_id();
        $first = (string) get_user_meta( $user_id, 'first_name_fa', true );
        $last = (string) get_user_meta( $user_id, 'last_name_fa', true );
        $user = get_userdata( $user_id );
        $province_code = (string) get_user_meta( $user_id, 'residence_province', true );
        $province = CityMap::get_provinces()[ $province_code ] ?? $province_code;
        $national_id = (string) get_user_meta( $user_id, 'national_id', true );
        $start_date = (string) get_post_meta( $competition_id, 'start_date', true );
        $end_date = (string) get_post_meta( $competition_id, 'end_date', true );
        $background = (string) get_post_meta( $competition_id, 'competition_card_background', true );
        if ( $background === '' ) $background = plugin_dir_url( IMAO_PLUGIN_FILE ) . 'assets/images/competition-card-template.png';
        $verification_url = self::verification_url( (int) $order->get_id(), (int) $item->get_id(), $user_id );
        $actual_weight = (float) $item->get_meta( self::META_WEIGHT, true );
        $registered_weight = trim( (string) $item->get_meta( 'دسته وزنی', true ) );
        $weight = $actual_weight > 0
            ? rtrim( rtrim( number_format( $actual_weight, 2, '.', '' ), '0' ), '.' ) . ' KG'
            : ( $registered_weight ?: '—' );
        return [
            'name' => trim( $first . ' ' . $last ) ?: ( $user ? $user->display_name : '' ),
            'user_id' => (string) $user_id,
            'order_id' => (string) $order->get_id(),
            'item_id' => (string) $item->get_id(),
            'competition_id' => (string) $competition_id,
            'competition_title' => (string) get_the_title( $competition_id ),
            'start_date' => $start_date,
            'end_date' => $end_date,
            'national_id' => $national_id,
            'competition_dates' => self::format_competition_date_range( $start_date, $end_date ),
            'insurance_date' => (string) $item->get_meta( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, true ) ?: (string) $item->get_meta( self::META_INSURANCE_DATE, true ),
            'federation_membership_expiry' => (string) $item->get_meta( self::ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY, true ),
            'weight' => $weight,
            'age' => (string) $item->get_meta( 'رده سنی', true ),
            'province' => $province,
            'photo' => (string) get_user_meta( $user_id, 'personal_photo', true ),
            'background' => esc_url_raw( $background ),
            'verification_url' => $verification_url,
            'qr_data_uri' => self::qr_data_uri( $verification_url ),
        ];
    }

    public static function format_competition_date_range( string $start, string $end ): string {
        $start = self::format_card_date( $start );
        $end = self::format_card_date( $end );
        if ( $start !== '' && $end !== '' ) {
            return $start . ' لغایت ' . $end;
        }
        return $start ?: $end;
    }

    private static function format_card_date( string $date ): string {
        $date = trim( str_replace( '-', '/', $date ) );
        if ( $date === '' ) {
            return '';
        }
        $parts = Date::split( $date );
        if ( count( $parts ) !== 3 || ! ctype_digit( str_replace( ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'], range(0, 9), $parts[0] ) ) ) {
            return self::persian_digits( $date );
        }
        $month_names = [1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور', 7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند'];
        $month = (int) $parts[1];
        if ( ! isset( $month_names[ $month ] ) ) {
            return self::persian_digits( $date );
        }
        return self::persian_digits( ltrim( $parts[2], '0' ) ?: '0' ) . ' ' . $month_names[ $month ] . ' ' . self::persian_digits( $parts[0] );
    }

    private static function persian_digits( string $value ): string {
        return strtr( $value, ['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹'] );
    }

    private static function verification_token( int $order_id, int $item_id, int $user_id ): string {
        return hash_hmac( 'sha256', $order_id . '|' . $item_id . '|' . $user_id, wp_salt( 'auth' ) );
    }

    private static function card_access_token( int $order_id, int $item_id ): string {
        return hash_hmac( 'sha256', 'competition-card|' . $order_id . '|' . $item_id, wp_salt( 'auth' ) );
    }

    private static function verification_url( int $order_id, int $item_id, int $user_id ): string {
        return add_query_arg(
            [
                'action' => 'imao_verify_competition_card',
                'order_id' => $order_id,
                'item_id' => $item_id,
                'token' => self::verification_token( $order_id, $item_id, $user_id ),
            ],
            admin_url( 'admin-post.php' )
        );
    }

    public static function qr_data_uri( string $content ): string {
        $renderer = new ImageRenderer( new RendererStyle( 420, 1 ), new SvgImageBackEnd() );
        $svg = ( new Writer( $renderer ) )->writeString( $content );
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    /** @param array<string,string> $data */
    private function card_document( array $data ): string {
        $e = static fn( string $value ): string => esc_html( $value ?: '—' );
        ob_start(); ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کارت مسابقه</title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#202124;font-family:Tahoma,Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:2;padding:10px;text-align:center;background:#fff}.toolbar button{padding:8px 20px;border:0;border-radius:5px;background:#17134b;color:#fff;font:700 14px Tahoma;cursor:pointer}.page{display:flex;justify-content:center;padding:18px}.card{position:relative;width:min(92vw,574px);aspect-ratio:565/776;background:center/100% 100% no-repeat;overflow:hidden;box-shadow:0 8px 30px #0008}.competition-date{position:absolute;z-index:4;top:39.5%;left:2%;width:86%;height:6%;display:flex;align-items:center;justify-content:flex-end;color:#c32921;font-size:clamp(12px,2.7vw,20px);font-weight:700;white-space:nowrap}.field{position:absolute;z-index:3;right:40%;width:22%;height:4.3%;display:flex;align-items:center;color:#050505;padding:0;font-weight:700;font-size:clamp(11px,2.1vw,18px);line-height:1}.field b{display:none}.field span{width:100%;text-align:right;white-space:nowrap}.name{top:48.85%;right:40%;width:30%}.national-id{top:54.85%}.province{top:60.85%}.weight{top:66.85%}.age{top:72.85%}.photo{position:absolute;z-index:3;left:3.5%;top:45.2%;width:28%;height:24%;background:#fff;border:3px solid #070707;object-fit:cover}.qr{position:absolute;z-index:3;left:2%;top:86%;width:15%;height:11%;display:block;background:#fff;object-fit:contain}.print-note{display:none}
@page{size:A4 portrait;margin:0}@media print{html,body{width:100%;height:100%;background:#fff}.toolbar{display:none}.page{width:100%;height:100%;padding:0;align-items:center}.card{height:100vh;width:auto;max-width:100vw;box-shadow:none;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div><main class="page"><section class="card" style="background-image:url('<?php echo esc_url( $data['background'] ); ?>')">
<div class="competition-date"><?php echo $e( $data['competition_dates'] ); ?></div>
<div class="field name"><b>نام و نام خانوادگی</b><span><?php echo $e( $data['name'] ); ?></span></div>
<div class="field national-id"><b>کد ملی</b><span><?php echo $e( $data['national_id'] ); ?></span></div>
<div class="field province"><b>استان</b><span><?php echo $e( $data['province'] ); ?></span></div>
<div class="field weight"><b>وزن</b><span><?php echo $e( $data['weight'] ); ?></span></div>
<div class="field age"><b>رده سنی</b><span><?php echo $e( $data['age'] ); ?></span></div>
<?php if ( $data['photo'] !== '' ) : ?><img class="photo" src="<?php echo esc_url( $data['photo'] ); ?>" alt="عکس ورزشکار"><?php else : ?><div class="photo"></div><?php endif; ?>
<img class="qr" src="<?php echo esc_attr( $data['qr_data_uri'] ); ?>" alt="QR استعلام اصالت کارت">
</section></main><script>window.addEventListener('load',function(){document.documentElement.classList.add('ready');if(!new URLSearchParams(location.search).has('preview'))window.setTimeout(function(){window.print()},250)});</script></body></html>
<?php return (string) ob_get_clean();
    }

    /** @return array<int,array{order:WC_Order,item:WC_Order_Item_Product}> */
    private function paid_entries( int $competition_id ): array {
        $product_id = (int) get_post_meta( $competition_id, '_linked_product_id', true );
        if ( ! $product_id || ! function_exists( 'wc_get_orders' ) ) return [];
        global $wpdb;
        $order_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT order_id FROM {$wpdb->prefix}woocommerce_order_items oi
             INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim ON oi.order_item_id=oim.order_item_id
             WHERE oi.order_item_type='line_item' AND oim.meta_key='_product_id' AND oim.meta_value=%d",
            $product_id
        ) );
        if ( ! $order_ids ) return [];
        $orders = wc_get_orders( [ 'limit' => -1, 'status' => [ 'processing', 'completed' ], 'include' => array_map( 'intval', $order_ids ), 'orderby' => 'date', 'order' => 'DESC' ] );
        $entries = [];
        $seen = [];
        foreach ( $orders as $order ) {
            $user_id = (int) $order->get_customer_id();
            if ( $user_id <= 0 ) {
                continue;
            }
            foreach ( $order->get_items() as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product || (int) $item->get_product_id() !== $product_id ) {
                    continue;
                }
                $key = (int) $order->get_id() . ':' . $user_id . ':' . $product_id;
                if ( isset( $seen[ $key ] ) ) {
                    continue;
                }
                $seen[ $key ] = true;
                $entries[] = [ 'order' => $order, 'item' => $item ];
            }
        }
        return $entries;
    }
}
