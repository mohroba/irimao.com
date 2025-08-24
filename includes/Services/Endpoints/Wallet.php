<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\Bootstrap;
use IMAOCustom\Helpers\Wallet as WalletHelper;
use WC_Order;
use WC_Order_Item_Fee;

class Wallet {
    private const META_LOG     = 'crm_wallet_log';
    private const META_USED    = 'crm_used_wallet';
    private const META_LINKED  = 'crm_linked_course';
    private const META_PAYOUT  = 'crm_payout_meta';

    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_wallet_endpoint', [ $this, 'endpoint_content' ] );
        add_shortcode( 'crm_wallet', [ $this, 'shortcode' ] );

        add_action( 'woocommerce_order_status_completed', [ $this, 'credit_topup' ] );

        add_action( 'woocommerce_review_order_after_order_total', [ $this, 'checkout_checkbox' ] );
        add_action( 'woocommerce_cart_totals_after_order_total', [ $this, 'checkout_checkbox' ] );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'store_wallet_flag' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_wallet_discount' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_used_wallet' ], 10, 2 );
        add_action( 'woocommerce_payment_complete', [ $this, 'after_payment' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'refund_wallet' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'refund_wallet' ], 10 );

        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'wallet', EP_ROOT | EP_PAGES );
    }

    public function add_menu_item( array $items ): array {
        $items['wallet'] = 'کیف پول';
        return $items;
    }

    public function endpoint_content(): void {
        echo $this->render_wallet_page();
    }

    public function shortcode(): string {
        return $this->render_wallet_page();
    }

    public static function get_balance( int $user_id = 0 ): float {
        return WalletHelper::get( $user_id );
    }

    public static function set_balance( int $user_id, float $amount ): void {
        WalletHelper::set( $user_id, $amount );
    }

    public static function add_balance( int $user_id, float $amount ): void {
        WalletHelper::add( $user_id, $amount );
    }

    public static function deduct_balance( int $user_id, float $amount ): void {
        WalletHelper::deduct( $user_id, $amount );
    }

    private static function add_log( int $user_id, float $amount, string $note = '' ): void {
        $log   = (array) get_user_meta( $user_id, self::META_LOG, true );
        $log[] = [
            'date'   => current_time( 'mysql' ),
            'amount' => $amount,
            'note'   => $note,
        ];
        update_user_meta( $user_id, self::META_LOG, $log );
    }

    public function credit_topup( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = $order->get_customer_id();
        $topup   = (float) $order->get_meta( 'wallet_topup' );
        if ( $user_id && $topup > 0 ) {
            self::add_balance( $user_id, $topup );
            $order->add_order_note( "Wallet credited: {$topup}" );
        }
    }

    public function checkout_checkbox(): void {
        if ( ! is_user_logged_in() ) {
            return;
        }
        $balance = self::get_balance();
        if ( $balance <= 0 ) {
            return;
        }
        $checked = WC()->session->get( 'crm_use_wallet' ) ? ' checked' : '';
        echo '<tr class="wallet-use"><th>استفاده از کیف پول (' . wc_price( $balance ) . ')</th><td><input type="checkbox" name="crm_use_wallet" value="1"' . $checked . '></td></tr>';
    }

    public function store_wallet_flag(): void {
        WC()->session->set( 'crm_use_wallet', ! empty( $_POST['crm_use_wallet'] ) );
    }

    public function apply_wallet_discount( $cart ): void {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! WC()->session->get( 'crm_use_wallet' ) || ! is_user_logged_in() ) {
            return;
        }
        $balance = self::get_balance();
        if ( $balance <= 0 ) {
            return;
        }
        $total = $cart->get_total( 'edit' );
        $use   = min( $balance, $total );
        if ( $use > 0 ) {
            $cart->add_fee( 'کیف پول', -$use, false );
            WC()->session->set( 'crm_wallet_use_amount', $use );
        }
    }

    public function save_used_wallet( $order, $data ): void {
        $use = (float) WC()->session->get( 'crm_wallet_use_amount' );
        if ( $use > 0 ) {
            $order->update_meta_data( self::META_USED, $use );
        }
    }

    public function after_payment( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = $order->get_customer_id();
        $used    = (float) $order->get_meta( self::META_USED );
        if ( $used > 0 ) {
            self::deduct_balance( $user_id, $used );
            self::add_log( $user_id, -$used, 'استفاده در سفارش #' . $order_id );
        }
        foreach ( $order->get_items() as $item ) {
            $course_id = (int) get_post_meta( $item->get_product_id(), self::META_LINKED, true );
            if ( ! $course_id ) {
                continue;
            }
            $payouts   = (array) get_post_meta( $course_id, self::META_PAYOUT, true );
            $line_total = $item->get_total();
            foreach ( $payouts as $p ) {
                $dest = (int) ( $p['user_id'] ?? 0 );
                if ( ! $dest ) {
                    continue;
                }
                $amt = ( $p['type'] === 'percent' ) ? $line_total * $p['value'] / 100 : (float) $p['value'];
                if ( $amt <= 0 ) {
                    continue;
                }
                self::add_balance( $dest, $amt );
                self::add_log( $dest, $amt, 'درآمد از دوره #' . $course_id . ' (سفارش ' . $order_id . ')' );
            }
        }
    }

    public function refund_wallet( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = $order->get_customer_id();
        $used    = (float) $order->get_meta( self::META_USED );
        if ( $used > 0 ) {
            self::add_balance( $user_id, $used );
            self::add_log( $user_id, $used, 'بازگشت وجه سفارش لغو شده #' . $order_id );
            $order->delete_meta_data( self::META_USED );
            $order->save();
        }
    }

    public function register_admin_page(): void {
        add_users_page( 'مدیریت کیف پول', 'مدیریت کیف پول', 'manage_options', 'crm-wallet-manager', [ $this, 'wallet_manager_page' ] );
    }

    public function enqueue_admin_assets( string $hook ): void {
        if ( $hook !== 'users_page_crm-wallet-manager' ) {
            return;
        }
        $url = plugin_dir_url( dirname( __DIR__, 2 ) );
        Bootstrap::enqueue();
        wp_enqueue_style( 'dt-css', $url . 'assets/css/jquery.dataTables.min.css' );
        wp_enqueue_script( 'dt-js', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery', 'bootstrap-js' ], null, true );
        wp_add_inline_script( 'dt-js', 'jQuery(function($){$("#crm-wallet-table").DataTable({language:{url:"https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json"},pageLength:50,order:[[1,"desc"]]});});' );
    }

    public function wallet_manager_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        $users = get_users( [ 'fields' => [ 'ID', 'display_name', 'user_email' ] ] );
        if ( isset( $_POST['crm_wallet_adj'] ) ) {
            check_admin_referer( 'crm_wallet_adj_nonce' );
            $uid  = (int) $_POST['user'];
            $amt  = (float) $_POST['amount'];
            $note = sanitize_text_field( $_POST['memo'] );
            $act  = sanitize_text_field( $_POST['action'] );
            if ( $act === 'set' ) {
                self::set_balance( $uid, $amt );
            } elseif ( $act === 'add' ) {
                self::add_balance( $uid, $amt );
            } elseif ( $act === 'sub' ) {
                self::deduct_balance( $uid, $amt );
            }
            self::add_log( $uid, $act === 'sub' ? -abs( $amt ) : $amt, 'مدیریت: ' . $note );
            echo '<div class="updated"><p>تغییر ذخیره شد.</p></div>';
        }
        echo '<div class="wrap"><h1>مدیریت کیف پول کاربران</h1>';
        wp_nonce_field( 'crm_wallet_adj_nonce' );
        echo '<table id="crm-wallet-table" class="widefat striped nowrap" style="width:100%"><thead><tr><th>کاربر</th><th>موجودی</th><th>مبلغ</th><th>پرداخت بابت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ( $users as $u ) {
            $bal = self::get_balance( $u->ID );
            echo '<tr><form method="post">'
                . '<td>' . esc_html( $u->display_name ) . ' (' . esc_html( $u->user_email ) . ')</td>'
                . '<td>' . wc_price( $bal ) . '</td>'
                . '<td><input type="number" step="0.01" name="amount" required style="width:100px"></td>'
                . '<td><input type="text" name="memo" style="width:100%"></td>'
                . '<td>'
                . '<input type="hidden" name="user" value="' . $u->ID . '">' 
                . '<button class="btn btn-success" name="action" value="add">افزایش</button> '
                . '<button class="btn btn-danger" name="action" value="sub">کاهش</button> '
                . '<button class="btn btn-secondary" name="action" value="set">تنظیم موجودی</button>'
                . '<input type="hidden" name="crm_wallet_adj" value="1">'
                . '</td></form></tr>';
        }
        echo '</tbody></table></div>';
    }

    private function render_wallet_page(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ کیف پول ابتدا وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $balance = self::get_balance( $user_id );
        if ( isset( $_POST['wallet_charge'], $_POST['amount'] ) ) {
            $amount     = max( 0, (float) $_POST['amount'] );
            $min_charge = ir_price( 10000 );
            if ( $amount < $min_charge ) {
                wc_add_notice( 'حداقل شارژ ' . wc_price( $min_charge ) . ' است.', 'error' );
            } else {
                $order = wc_create_order();
                $item  = new WC_Order_Item_Fee();
                $item->set_name( 'شارژ کیف پول' );
                $item->set_amount( $amount );
                $item->set_total( $amount );
                $order->add_item( $item );
                $order->update_meta_data( 'wallet_topup', $amount );
                $order->calculate_totals();
                $order->set_customer_id( $user_id );
                $order->update_status( 'pending', 'Wallet top-up' );
                $order->save();
                wp_safe_redirect( $order->get_checkout_payment_url() );
                exit;
            }
        }
        ob_start();
        ?>
        <div id="wallet-box" class="needs-swal">
            <h4>موجودی کیف پول: <span class="wallet-balance"><?php echo wc_price( $balance ); ?></span></h4>
            <form method="post" class="wallet-charge-form">
                <label for="wallet-amount">مبلغ شارژ (تومان):</label>
                <input type="number" id="wallet-amount" name="amount" min="100000" step="10000">
                <button class="btn button" type="submit" name="wallet_charge">پرداخت و شارژ</button>
            </form>
            <?php
            $orders = wc_get_orders( [
                'customer_id' => $user_id,
                'limit'       => -1,
                'orderby'     => 'date',
                'order'       => 'DESC',
                'meta_key'    => 'wallet_topup',
            ] );
            if ( $orders ) : ?>
                <h4 class="wallet-section-title">جدول پرداخت های شما</h4>
                <table class="wallet-table">
                    <thead><tr><th>#</th><th>تاریخ</th><th>مبلغ شارژ</th><th>وضعیت سفارش</th></tr></thead>
                    <tbody>
                    <?php $i = 1; foreach ( $orders as $o ) : $amount = (float) $o->get_meta( 'wallet_topup' ); $status = wc_get_order_status_name( $o->get_status() ); $date = $o->get_date_created()->date_i18n( 'Y/m/d H:i' ); ?>
                        <tr><td><?php echo $i++; ?></td><td><?php echo esc_html( $date ); ?></td><td><?php echo wc_price( $amount ); ?></td><td><?php echo esc_html( $status ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p class="wallet-no-orders">هیچ تراکنشی برای شارژ کیف پول ثبت نشده است.</p>
            <?php endif; ?>
            <?php
            $logs = (array) get_user_meta( $user_id, self::META_LOG, true );
            usort( $logs, fn( $a, $b ) => strtotime( $b['date'] ) <=> strtotime( $a['date'] ) );
            if ( $logs ) : ?>
                <h4 class="wallet-section-title">جدول تراکنش های سامانه</h4>
                <table class="wallet-table log-table">
                    <thead><tr><th>تاریخ</th><th>مبلغ</th><th>پرداخت بابت</th></tr></thead>
                    <tbody>
                    <?php foreach ( $logs as $l ) : ?>
                        <tr><td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $l['date'] ) ) ); ?></td><td><?php echo wc_price( $l['amount'] ); ?></td><td><?php echo esc_html( $l['note'] ); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
