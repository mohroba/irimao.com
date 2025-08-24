<?php

namespace IMAOCustom\Services;

use WP_Post;

class Courses {
    private const META_LINKED_PRODUCT = '_linked_product_id';
    private const META_LINKED_COURSE  = '_linked_post_id';
    private const META_PAYOUTS       = '_course_payouts';
    private const META_MANUAL        = '_manual_attendees';

    /**
     * Fields for course details meta box.
     *
     * @return array<string,string>
     */
    public static function detail_fields(): array {
        return [
            'course_code'       => 'کد',
            'start_date'        => 'تاریخ شروع',
            'end_date'          => 'تاریخ پایان',
            'exam_date'         => 'تاریخ آزمون',
            'registration_start'=> 'شروع ثبت‌نام',
            'registration_end'  => 'پایان ثبت‌نام',
            'attendance'        => 'نوع حضور',
            'course_time'       => 'ساعت برگزاری دوره',
            'organizer'         => 'مسئول برگزاری',
            'organizer_tel'     => 'شماره همراه مسئول برگزاری',
            'instructor'        => 'مدرس دوره',
            'examiner'          => 'ممتحن',
            'supervisor'        => 'ناظر',
            'address'           => 'آدرس محل برگزاری',
            'min_degree'        => 'حداقل درجه فنی',
            'points'            => 'امتیاز دوره',
            'price'             => 'شهریه دوره (تومان)',
        ];
    }

    public function register(): void {
        add_action( 'init', [ $this, 'register_taxonomies' ], 5 );
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function register_taxonomies(): void {
        $tax = function( string $slug, string $singular, string $plural, bool $hier = false ): void {
            $labels = [
                'name'          => $plural,
                'singular_name' => $singular,
                'add_new_item'  => "افزودن $singular جدید",
                'edit_item'     => "ویرایش $singular",
                'search_items'  => "جستجوی $plural",
                'all_items'     => "همه $plural",
            ];
            $args = [
                'hierarchical'      => $hier,
                'labels'            => $labels,
                'public'            => true,
                'show_admin_column' => true,
                'rewrite'           => [ 'slug' => $slug ],
                'show_in_rest'      => true,
            ];
            register_taxonomy( $slug, 'course', $args );
        };

        $tax( 'gender',      'جنسیت',      'جنسیت' );
        $tax( 'board',       'هیئت',       'هیئت‌ها', true );
        $tax( 'course_type', 'نوع دوره',   'انواع دوره' );
        $tax( 'age_category','رده سنی',    'رده‌های سنی', true );
        $tax( 'level',       'سطح',        'سطوح', true );
    }

    public function register_cpt(): void {
        $labels = [
            'name'          => 'دوره‌ها',
            'singular_name' => 'دوره',
            'add_new'       => 'افزودن دوره',
            'add_new_item'  => 'دورهٔ جدید',
            'edit_item'     => 'ویرایش دوره',
            'new_item'      => 'دورهٔ جدید',
            'view_item'     => 'مشاهدهٔ دوره',
            'search_items'  => 'جستجوی دوره',
            'menu_name'     => 'دوره‌ها',
        ];

        register_post_type( 'course', [
            'labels'       => $labels,
            'public'       => true,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-welcome-learn-more',
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'courses' ],
            'supports'     => [ 'title', 'editor', 'thumbnail' ],
            'show_in_rest' => true,
        ] );
    }

    public function add_meta_boxes(): void {
        add_meta_box( 'crm_details', 'جزئیات', [ $this, 'render_details_box' ], 'course', 'normal', 'high' );
        add_meta_box( 'crm_payouts', 'ذی‌نفعان', [ $this, 'render_payouts_box' ], 'course', 'normal', 'default' );
        add_meta_box( 'crm_manual',  'حضور دستی', [ $this, 'render_manual_box' ],  'course', 'side',   'default' );
    }

    public function render_details_box( WP_Post $post ): void {
        wp_nonce_field( 'crm_save_details', 'crm_details_nonce' );
        $val          = static fn( string $k ) => esc_attr( get_post_meta( $post->ID, $k, true ) );
        $fields       = self::detail_fields();
        $number_field = [ 'points', 'min_degree' ];
        $tel_fields   = [ 'organizer_tel' ];
        $date_fields  = [ 'start_date', 'end_date', 'exam_date', 'registration_start', 'registration_end' ];
        echo '<table class="form-table"><tbody>';
        foreach ( $fields as $k => $label ) {
            $type  = 'text';
            $class = '';
            if ( in_array( $k, $date_fields, true ) ) {
                $class = 'class="crm-date" data-jdp data-jdp-only-date';
            } elseif ( $k === 'price' ) {
                $class = 'class="crm-price"';
            } elseif ( in_array( $k, $number_field, true ) ) {
                $type = 'number';
            } elseif ( in_array( $k, $tel_fields, true ) ) {
                $type = 'tel';
            }
            printf(
                '<tr><th><label for="%1$s">%2$s</label></th><td><input type="%3$s" id="%1$s" name="%1$s" value="%4$s" style="width:100%%" %5$s></td></tr>',
                esc_attr( $k ),
                esc_html( $label ),
                $type,
                $val( $k ),
                $class
            );
        }
        echo '</tbody></table>';
    }

    public function render_payouts_box( WP_Post $post ): void {
        wp_nonce_field( 'crm_save_payouts', 'crm_payouts_nonce' );
        $rows = (array) get_post_meta( $post->ID, self::META_PAYOUTS, true );
        $user_opts = function( $sel ) {
            $opts = '';
            foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $u ) {
                $opts .= sprintf( '<option value="%d"%s>%s</option>', $u->ID, selected( $u->ID, $sel, false ), esc_html( $u->display_name ) );
            }
            return $opts;
        };
        echo '<table class="widefat" id="crm-payout-table"><thead><tr><th>کاربر</th><th>نوع</th><th>مقدار</th><th></th></tr></thead><tbody id="crm-payout-body">';
        $rowTpl = function( $uid = '', $type = 'percent', $val = '' ) use ( $user_opts ) {
            return '<tr>'
                . '<td><select name="payout_user_id[]" class="crm-select2" style="width:100%">' . $user_opts( $uid ) . '</select></td>'
                . '<td><select name="payout_type[]"><option value="percent"' . selected( 'percent', $type, false ) . '>درصد</option><option value="fixed"' . selected( 'fixed', $type, false ) . '>مبلغ ثابت</option></select></td>'
                . '<td><input type="number" step="0.01" name="payout_value[]" value="' . esc_attr( $val ) . '"></td>'
                . '<td><span class="dashicons dashicons-no-alt crm-remove-row" style="cursor:pointer;color:#c00"></span></td>'
                . '</tr>';
        };
        if ( $rows ) {
            foreach ( $rows as $r ) {
                echo $rowTpl( $r['user_id'] ?? '', $r['type'] ?? 'percent', $r['value'] ?? '' );
            }
        }
        echo '</tbody></table><button type="button" class="button" id="crm-add-payout">افزودن</button>';
        ?>
        <script>jQuery(function($){
            $('#crm-add-payout').on('click',function(){ $('#crm-payout-body').append(`<?php echo addslashes( $rowTpl() ); ?>`); });
            $(document).on('click','.crm-remove-row',function(){ $(this).closest('tr').remove(); });
        });</script>
        <?php
    }

    public function render_manual_box( WP_Post $post ): void {
        wp_nonce_field( 'crm_save_manual', 'crm_manual_nonce' );
        $att = (array) get_post_meta( $post->ID, self::META_MANUAL, true );
        echo '<p><select multiple name="manual_attendees[]" class="crm-select2" style="width:100%">';
        foreach ( get_users( [ 'fields' => [ 'ID', 'display_name' ] ] ) as $u ) {
            printf( '<option value="%d"%s>%s</option>', $u->ID, selected( in_array( $u->ID, $att, true ), true, false ), esc_html( $u->display_name ) );
        }
        echo '</select></p><p style="font-size:12px">نگه‌داشتن CTRL برای چند انتخاب.</p>';
    }

    public function save_meta( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== 'course' ) {
            return;
        }

        if ( isset( $_POST['crm_details_nonce'] ) ) {
            foreach ( array_keys( self::detail_fields() ) as $k ) {
                if ( ! isset( $_POST[ $k ] ) ) {
                    continue;
                }
                $val = sanitize_text_field( $_POST[ $k ] );
                if ( $k === 'price' ) {
                    $val = str_replace( [',', ' '], '', $val );
                }
                update_post_meta( $post_id, $k, $val );
            }
        }

        if ( isset( $_POST['crm_payouts_nonce'] ) ) {
            $rows = [];
            if ( ! empty( $_POST['payout_user_id'] ) ) {
                foreach ( (array) $_POST['payout_user_id'] as $i => $uid ) {
                    $uid = (int) $uid;
                    if ( ! $uid ) { continue; }
                    $rows[] = [
                        'user_id' => $uid,
                        'type'    => sanitize_text_field( $_POST['payout_type'][ $i ] ?? 'percent' ),
                        'value'   => (float) ( $_POST['payout_value'][ $i ] ?? 0 ),
                    ];
                }
            }
            update_post_meta( $post_id, self::META_PAYOUTS, $rows );
        }

        if ( isset( $_POST['crm_manual_nonce'] ) ) {
            $att = array_map( 'intval', $_POST['manual_attendees'] ?? [] );
            update_post_meta( $post_id, self::META_MANUAL, $att );
        }

        $this->sync_product( $post_id );
    }

    private function sync_product( int $post_id ): void {
        if ( ! class_exists( 'WC_Product' ) ) {
            return;
        }
        $price   = (float) get_post_meta( $post_id, 'price', true );
        $prod_id = (int) get_post_meta( $post_id, self::META_LINKED_PRODUCT, true );
        if ( $prod_id && ( $prod = wc_get_product( $prod_id ) ) ) {
            $prod->set_name( get_the_title( $post_id ) );
            $prod->set_regular_price( $price );
            $prod->save();
        } else {
            $prod = new \WC_Product_Simple();
            $prod->set_name( get_the_title( $post_id ) );
            $prod->set_regular_price( $price );
            $prod->set_virtual( true );
            $prod->set_catalog_visibility( 'hidden' );
            $prod_id = $prod->save();
            update_post_meta( $post_id, self::META_LINKED_PRODUCT, $prod_id );
            update_post_meta( $prod_id, self::META_LINKED_COURSE, $post_id );
        }
    }

    public function enqueue_admin_assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'course' ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) );
        wp_enqueue_style( 'imao-jdp', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-jdp', $url . 'assets/js/jalalidatepicker.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '1.0.0', true );
        wp_add_inline_script(
            'imao-jdp',
            'jQuery(function($){$(".crm-select2").select2({dir:"rtl",width:"resolve"});jalaliDatepicker.startWatch();$(".crm-price").each(function(){var v=$(this).val().replace(/[\s,]/g,"");if(v){$(this).val(Number(v).toLocaleString("fa-IR"));}}).on("input",function(){var v=$(this).val().replace(/[\s,]/g,"");if(v){$(this).val(Number(v).toLocaleString("fa-IR"));}});});'
        );
    }
}
