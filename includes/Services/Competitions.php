<?php
namespace IMAOCustom\Services;

use WP_Query;

class Competitions {
    public function register(): void {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_shortcode( 'crm_competitions_list', [ $this, 'competitions_list_shortcode' ] );
        add_shortcode( 'crm_competition_details', [ $this, 'competition_details_shortcode' ] );
        add_shortcode( 'crm_user_competitions', [ $this, 'user_competitions_shortcode' ] );
    }

    public function register_post_type(): void {
        register_post_type( 'competition', [
            'labels' => [
                'name' => 'مسابقات',
                'singular_name' => 'مسابقه',
            ],
            'public' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'menu_icon' => 'dashicons-awards',
            'has_archive' => true,
            'rewrite' => [ 'slug' => 'competitions' ],
            'supports' => [ 'title', 'thumbnail' ],
            'taxonomies' => [ 'weight_class' ],
            'show_in_rest' => true,
        ] );

        register_taxonomy( 'weight_class', [ 'competition' ], [
            'labels' => [
                'name' => 'کلاس‌های وزنی',
                'singular_name' => 'کلاس وزنی',
            ],
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'show_in_rest' => true,
        ] );
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

        $tax_cols = [ 'weight_class' => 'کلاس وزنی' ];
        ob_start();
        echo '<div class="sd-container">';
        echo '<div class="sd-header">لیست مسابقات</div>';
        echo '<table class="shop_table shop_table_responsive crm-competition-table"><thead><tr><th>#</th><th>عنوان</th>';
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
        $price   = get_post_meta( $cid, 'price', true );
        $prod_id = (int) get_post_meta( $cid, '_linked_product_id', true );

        ob_start();
        ?>
        <form method="get" action="<?php echo esc_url( wc_get_cart_url() ); ?>" class="crm-competition-form">
            <input type="hidden" name="add-to-cart" value="<?php echo $prod_id; ?>">
            <div class="crm-single-course">
                <h3><?php echo esc_html( get_the_title( $cid ) ); ?></h3>
                <table>
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

        $orders = wc_get_orders( [
            'customer_id' => $user_id,
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => [ 'completed', 'processing', 'pending', 'on-hold' ],
        ] );

        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                $comp_id = (int) get_post_meta( $item->get_product_id(), '_linked_post_id', true );
                if ( ! $comp_id || get_post_type( $comp_id ) !== 'competition' ) {
                    continue;
                }

                $rows[] = [
                    'competition_id' => $comp_id,
                    'order_date'     => $order->get_date_created()->date_i18n( 'Y/m/d' ),
                    'amount'         => $item->get_total(),
                    'status'         => wc_get_order_status_name( $order->get_status() ),
                    'weight_class'   => $item->get_meta( 'کلاس وزنی', true ),
                ];
            }
        }

        if ( ! $rows ) {
            return '<p>تا کنون در مسابقه‌ای شرکت نکرده‌اید.</p>';
        }

        ob_start();
        echo '<table class="shop_table shop_table_responsive"><thead><tr><th>#</th><th>مسابقه</th><th>کلاس وزنی</th><th>تاریخ</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
        $i = 1;
        foreach ( $rows as $r ) {
            echo '<tr>';
            echo '<td>' . ( $i++ ) . '</td>';
            echo '<td><a href="' . esc_url( get_permalink( $r['competition_id'] ) ) . '">' . esc_html( get_the_title( $r['competition_id'] ) ) . '</a></td>';
            echo '<td>' . esc_html( $r['weight_class'] ?: '—' ) . '</td>';
            echo '<td>' . esc_html( $r['order_date'] ) . '</td>';
            echo '<td>' . wc_price( $r['amount'] ) . '</td>';
            echo '<td>' . esc_html( $r['status'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
        return ob_get_clean();
    }
}
