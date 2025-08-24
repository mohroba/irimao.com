<?php
namespace IMAOCustom\Services;

use WP_Post;
use WP_Query;

class Competitions {
    private const META_LINKED_PRODUCT     = '_linked_product_id';
    private const META_LINKED_COMPETITION = '_linked_post_id';

    /**
     * Fields for competition details meta box.
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
        add_action( 'init', [ $this, 'populate_board_terms' ], 6 );
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes', [ $this, 'add_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_meta' ], 10, 3 );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_shortcode( 'crm_competitions_list', [ $this, 'competitions_list_shortcode' ] );
        add_shortcode( 'crm_competition_details', [ $this, 'competition_details_shortcode' ] );
        add_shortcode( 'crm_user_competitions', [ $this, 'user_competitions_shortcode' ] );
        add_filter( 'woocommerce_add_cart_item_data', [ $this, 'add_cart_item_data' ], 10, 2 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'add_item_data' ], 10, 2 );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'add_order_line_item_meta' ], 10, 2 );
    }

    public function register_taxonomies(): void {
        $tax = function ( string $slug, string $singular, string $plural, bool $hier = false ): void {
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
            if ( taxonomy_exists( $slug ) ) {
                register_taxonomy_for_object_type( $slug, 'competition' );
            } else {
                register_taxonomy( $slug, [ 'competition' ], $args );
            }
        };

        $tax( 'gender',       'جنسیت',       'جنسیت' );
        $tax( 'board',        'هیئت',        'هیئت‌ها', true );
        $tax( 'course_type',  'نوع دوره',   'انواع دوره' );
        $tax( 'age_category', 'رده سنی',    'رده‌های سنی', true );
        $tax( 'level',        'سطح',         'سطوح', true );
        $tax( 'weight_class', 'کلاس وزنی',   'کلاس‌های وزنی' );
    }

    /**
     * Ensure board taxonomy is populated with province terms.
     */
    public function populate_board_terms(): void {
        if ( ! function_exists( 'wp_insert_term' ) || ! function_exists( 'term_exists' ) ) {
            return;
        }
        foreach ( Courses::province_terms() as $slug => $name ) {
            if ( ! term_exists( $slug, 'board' ) ) {
                wp_insert_term( $name, 'board', [ 'slug' => $slug ] );
            }
        }
    }

    public function register_cpt(): void {
        $labels = [
            'name'          => 'مسابقات',
            'singular_name' => 'مسابقه',
            'add_new'       => 'افزودن مسابقه',
            'add_new_item'  => 'مسابقهٔ جدید',
            'edit_item'     => 'ویرایش مسابقه',
            'new_item'      => 'مسابقهٔ جدید',
            'view_item'     => 'مشاهدهٔ مسابقه',
            'search_items'  => 'جستجوی مسابقه',
            'menu_name'     => 'مسابقات',
        ];

        register_post_type( 'competition', [
            'labels'       => $labels,
            'public'       => true,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-awards',
            'has_archive'  => true,
            'rewrite'      => [ 'slug' => 'competitions' ],
            'supports'     => [ 'title', 'thumbnail' ],
            'taxonomies'   => [ 'gender','board','course_type','age_category','weight_class','level' ],
            'show_in_rest' => true,
        ] );
    }

    public function add_meta_boxes(): void {
        add_meta_box( 'crm_details', 'جزئیات', [ $this, 'render_details_box' ], 'competition', 'normal', 'high' );
    }

    public function render_details_box( WP_Post $post ): void {
        wp_nonce_field( 'crm_save_details', 'crm_details_nonce' );
        $val          = static fn( string $k ) => esc_attr( get_post_meta( $post->ID, $k, true ) );
        $fields       = self::detail_fields();
        $number_field = [ 'points', 'min_degree' ];
        $tel_fields   = [ 'organizer_tel' ];
        $date_fields  = [ 'start_date', 'end_date', 'exam_date', 'registration_start', 'registration_end' ];
        echo '<table class="form-table striped"><tbody>';
        foreach ( $fields as $k => $label ) {
            $type  = 'text';
            $class = '';
            if ( in_array( $k, $date_fields, true ) ) {
                $class = 'class="crm-date" data-jdp data-jdp-only-date';
            } elseif ( $k === 'price' ) {
                $type  = 'number';
                $class = 'min="0" step="1000"';
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

    public function save_meta( int $post_id, WP_Post $post, bool $update ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( $post->post_type !== 'competition' ) {
            return;
        }
        if ( isset( $_POST['crm_details_nonce'] ) ) {
            foreach ( array_keys( self::detail_fields() ) as $k ) {
                if ( ! isset( $_POST[ $k ] ) ) {
                    continue;
                }
                if ( $k === 'price' ) {
                    $val = absint( $_POST[ $k ] );
                } else {
                    $val = sanitize_text_field( $_POST[ $k ] );
                }
                update_post_meta( $post_id, $k, $val );
            }
        }
        $this->sync_product( $post_id );
    }

    public function enqueue_admin_assets(): void {
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'competition' ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__ ) );
        wp_enqueue_style( 'imao-jdp', $url . 'assets/css/jalalidatepicker.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-select2', $url . 'assets/css/select2.min.css', [], '1.0.0' );
        wp_enqueue_style( 'imao-dt', $url . 'assets/css/jquery.dataTables.min.css', [], '1.0.0' );
        wp_enqueue_script( 'imao-jdp', $url . 'assets/js/jalalidatepicker.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-select2', $url . 'assets/js/select2.min.js', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'imao-dt', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], '1.0.0', true );
        wp_add_inline_script(
            'imao-jdp',
            'jQuery(function($){$(".crm-select2").select2({dir:"rtl",width:"resolve"});jalaliDatepicker.startWatch();});'
        );
        wp_add_inline_script(
            'imao-dt',
            'jQuery(function($){$("#crm-attendees-table").DataTable({language:{url:"https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json"},pageLength:20});});'
        );
    }

    public function add_cart_item_data( $data, $prod_id ) {
        if ( isset( $_REQUEST['weight_class_term'] ) ) {
            $data['weight_class_term'] = (int) $_REQUEST['weight_class_term'];
        }
        if ( isset( $_REQUEST['age_category_term'] ) ) {
            $data['age_category_term'] = (int) $_REQUEST['age_category_term'];
        }
        return $data;
    }

    public function add_item_data( $data, $cart_item ) {
        if ( ! empty( $cart_item['weight_class_term'] ) ) {
            $term = get_term( $cart_item['weight_class_term'], 'weight_class' );
            if ( $term ) {
                $data[] = [ 'name' => 'کلاس وزنی', 'value' => $term->name ];
            }
        }
        if ( ! empty( $cart_item['age_category_term'] ) ) {
            $term = get_term( $cart_item['age_category_term'], 'age_category' );
            if ( $term ) {
                $data[] = [ 'name' => 'رده سنی', 'value' => $term->name ];
            }
        }
        return $data;
    }

    public function add_order_line_item_meta( $item, $cart_item ) {
        if ( ! empty( $cart_item['weight_class_term'] ) ) {
            $term = get_term( $cart_item['weight_class_term'], 'weight_class' );
            if ( $term ) {
                $item->add_meta_data( 'کلاس وزنی', $term->name, true );
            }
        }
        if ( ! empty( $cart_item['age_category_term'] ) ) {
            $term = get_term( $cart_item['age_category_term'], 'age_category' );
            if ( $term ) {
                $item->add_meta_data( 'رده سنی', $term->name, true );
            }
        }
    }

    public function competitions_list_shortcode(): string {
        $q = new WP_Query( [
            'post_type' => 'competition',
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
        ] );
        if ( ! $q->have_posts() ) {
            return '<p>مسابقه‌ای موجود نیست.</p>';
        }

        $tax_cols = [
            'weight_class'  => 'کلاس وزنی',
            'gender'        => 'جنسیت',
            'board'         => 'هیئت',
            'age_category'  => 'رده سنی',
            'level'         => 'سطح',
        ];
        ob_start();
        echo '<div class="sd-container">';
        echo '<div class="sd-header">لیست مسابقات</div>';
        echo '<table class="shop_table shop_table_responsive crm-competition-table striped"><thead><tr><th>#</th><th>عنوان</th>';
        foreach ( $tax_cols as $label ) {
            echo "<th>{$label}</th>";
        }
        echo '<th>قیمت</th><th>اقدام</th></tr></thead><tbody>';
        $i = 1;
        while ( $q->have_posts() ) :
            $q->the_post();
            $pid = get_the_ID();
            echo '<tr><td>' . ( $i++ ) . '</td><td>' . get_the_title() . '</td>';
            foreach ( $tax_cols as $slug => $label ) {
                $terms = wp_get_post_terms( $pid, $slug, [ 'fields' => 'names' ] );
                echo '<td>' . ( $terms ? implode( ', ', $terms ) : '—' ) . '</td>';
            }
            $price = get_post_meta( $pid, 'price', true );
            echo '<td>' . wc_price( $price ) . '</td>';
            echo '<td><a href="' . esc_url( '/my-account/competition-details/?competition_id=' . $pid ) . '">جزئیات / ثبت‌نام</a></td></tr>';
        endwhile;
        wp_reset_postdata();
        echo '</tbody></table></div>';
        return ob_get_clean();
    }

    public function competition_details_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts );
        $cid  = intval( $atts['id'] ?: ( $_GET['competition_id'] ?? 0 ) );
        if ( ! $cid || get_post_type( $cid ) !== 'competition' ) {
            return '<p>مسابقه پیدا نشد.</p>';
        }

        $weights = wp_get_post_terms( $cid, 'weight_class' );
        $ages    = wp_get_post_terms( $cid, 'age_category' );
        $price   = get_post_meta( $cid, 'price', true );
        $prod_id = (int) get_post_meta( $cid, self::META_LINKED_PRODUCT, true );
        if ( ! $prod_id ) {
            $prod_id = $this->sync_product( $cid );
        }

        ob_start();
        ?>
        <form method="get" action="<?php echo esc_url( wc_get_cart_url() ); ?>" class="crm-competition-form">
            <input type="hidden" name="add-to-cart" value="<?php echo $prod_id; ?>">
            <div class="crm-single-course">
                <h3><?php echo esc_html( get_the_title( $cid ) ); ?></h3>
                <table class="striped">
                    <tbody>
                        <tr><th>کد</th><td><?php echo esc_html( get_post_meta( $cid, 'course_code', true ) ); ?></td></tr>
                        <tr><th>قیمت</th><td><?php echo wc_price( $price ); ?></td></tr>
                        <?php if ( $conditions = get_post_meta( $cid, 'special_conditions', true ) ) : ?>
                            <tr><th>شرایط خاص</th><td><?php echo nl2br( esc_html( $conditions ) ); ?></td></tr>
                        <?php endif; ?>
                        <tr><th>کلاس وزنی</th><td>
                            <select name="weight_class_term" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ( $weights as $t ) : ?>
                                    <option value="<?php echo $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                        <tr><th>رده سنی</th><td>
                            <select name="age_category_term" required>
                                <option value="">— انتخاب کنید —</option>
                                <?php foreach ( $ages as $t ) : ?>
                                    <option value="<?php echo $t->term_id; ?>"><?php echo esc_html( $t->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td></tr>
                    </tbody>
                </table>
                <p style="text-align:center"><button type="submit" class="crm-buy-btn">پرداخت و ثبت‌نام</button></p>
            </div>
        </form>
        <?php
        return ob_get_clean();
    }

    public function user_competitions_shortcode(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ مسابقات ابتدا وارد شوید.</p>';
        }

        $user_id = get_current_user_id();
        $rows    = [];

        $orders = wc_get_orders(
            [
                'customer_id' => $user_id,
                'limit'       => -1,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'status'      => [ 'completed', 'processing', 'pending', 'on-hold' ],
            ]
        );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $comp_id = (int) get_post_meta( $item->get_product_id(), self::META_LINKED_COMPETITION, true );
                if ( ! $comp_id || get_post_type( $comp_id ) !== 'competition' ) {
                    continue;
                }

                $rows[] = [
                    'competition_id' => $comp_id,
                    'order_id'       => $order->get_id(),
                    'order_date'     => $order->get_date_created()->date_i18n( 'Y/m/d' ),
                    'amount'         => $item->get_total(),
                    'status'         => wc_get_order_status_name( $order->get_status() ),
                    'weight_class'   => $item->get_meta( 'کلاس وزنی', true ),
                    'age_category'   => $item->get_meta( 'رده سنی', true ),
                ];
            }
        }

        if ( ! $rows ) {
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
                        <th>کلاس وزنی</th>
                        <th>رده سنی</th>
                        <th>شماره سفارش</th>
                        <th>تاریخ سفارش</th>
                        <th>مبلغ پرداختی</th>
                        <th>وضعیت سفارش</th>
                        <th>اقدام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ( $rows as $r ) : $cid = $r['competition_id']; ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo esc_html( get_the_title( $cid ) ); ?></td>
                            <td><?php echo esc_html( get_post_meta( $cid, 'course_code', true ) ); ?></td>
                            <td><?php echo esc_html( $r['weight_class'] ?: '—' ); ?></td>
                            <td><?php echo esc_html( $r['age_category'] ?: '—' ); ?></td>
                            <td>#<?php echo $r['order_id']; ?></td>
                            <td><?php echo esc_html( $r['order_date'] ); ?></td>
                            <td><?php echo wc_price( $r['amount'] ); ?></td>
                            <td><?php echo esc_html( $r['status'] ); ?></td>
                            <td><a href="<?php echo esc_url( '/my-account/competition-details/?competition_id=' . $cid ); ?>">جزئیات</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }

    private function sync_product( int $competition_id ): int {
        if ( ! class_exists( 'WC_Product' ) ) {
            return 0;
        }
        $price   = (float) get_post_meta( $competition_id, 'price', true );
        $prod_id = (int) get_post_meta( $competition_id, self::META_LINKED_PRODUCT, true );
        if ( $prod_id && ( $prod = wc_get_product( $prod_id ) ) ) {
            $prod->set_name( get_the_title( $competition_id ) );
            $prod->set_regular_price( $price );
            $prod->save();
        } else {
            $prod = new \WC_Product_Simple();
            $prod->set_name( get_the_title( $competition_id ) );
            $prod->set_regular_price( $price );
            $prod->set_virtual( true );
            $prod->set_catalog_visibility( 'hidden' );
            $prod_id = $prod->save();
            update_post_meta( $competition_id, self::META_LINKED_PRODUCT, $prod_id );
            update_post_meta( $prod_id, self::META_LINKED_COMPETITION, $competition_id );
        }
        return $prod_id;
    }
}
