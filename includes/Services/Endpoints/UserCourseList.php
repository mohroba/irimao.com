<?php

namespace IMAOCustom\Services\Endpoints;

class UserCourseList {
    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_item' ] );
        add_action( 'woocommerce_account_user-course-list_endpoint', [ $this, 'content' ] );
        add_shortcode( 'crm_user_courses', [ $this, 'shortcode' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'user-course-list', EP_ROOT | EP_PAGES );
    }

    public function menu_item( array $items ): array {
        $items['user-course-list'] = 'دوره‌های من';
        return $items;
    }

    public function content(): void {
        echo $this->render();
    }

    public function shortcode(): string {
        return $this->render();
    }

    private function render(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ دوره‌های خریداری‌شده ابتدا وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $rows = [];
        $orders = wc_get_orders([
            'customer_id' => $user_id,
            'limit'       => -1,
            'orderby'     => 'date',
            'order'       => 'DESC',
            'status'      => [ 'completed','processing','pending','on-hold' ],
        ]);
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $it ) {
                $course_id = (int) get_post_meta( $it->get_product_id(), '_linked_post_id', true );
                if ( ! $course_id || get_post_type( $course_id ) !== 'course' ) {
                    continue;
                }
                $rows[] = [
                    'course_id'  => $course_id,
                    'order_id'   => $order->get_id(),
                    'order_date' => $order->get_date_created()->date_i18n( 'Y/m/d' ),
                    'amount'     => $it->get_total(),
                    'status'     => wc_get_order_status_name( $order->get_status() ),
                ];
            }
        }
        if ( ! $rows ) {
            return '<p>تا کنون دوره‌ای خریداری نکرده‌اید.</p>';
        }
        ob_start();
        ?>
        <div class="crm-course-wrap">
            <div class="crm-course-title">لیست دوره‌های شما</div>
            <table class="crm-course-table striped">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>نام دوره</th>
                        <th>کد دوره</th>
                        <th>تاریخ شروع</th>
                        <th>شماره سفارش</th>
                        <th>تاریخ سفارش</th>
                        <th>مبلغ پرداختی</th>
                        <th>وضعیت سفارش</th>
                        <th>اقدام</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $i = 1; foreach ( $rows as $r ) : $cid = $r['course_id']; ?>
                        <tr>
                            <td><?php echo $i++; ?></td>
                            <td><?php echo esc_html( get_the_title( $cid ) ); ?></td>
                            <td><?php echo esc_html( get_post_meta( $cid, 'course_code', true ) ); ?></td>
                            <td><?php echo esc_html( get_post_meta( $cid, 'start_date', true ) ); ?></td>
                            <td>#<?php echo $r['order_id']; ?></td>
                            <td><?php echo esc_html( $r['order_date'] ); ?></td>
                            <td><?php echo wc_price( $r['amount'] ); ?></td>
                            <td><?php echo esc_html( $r['status'] ); ?></td>
                            <td><a href="<?php echo esc_url( '/my-account/course-details/?course_id=' . $cid ); ?>">جزئیات</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
        return ob_get_clean();
    }
}
