<?php

namespace IMAOCustom\Services\Endpoints;

use IMAOCustom\Helpers\Price;
use IMAOCustom\Helpers\Wallet as WalletHelper;
use WC_Order_Item_Fee;

class Wallet {
    private const META_LOG            = 'crm_wallet_log';
    private const META_USED           = 'crm_used_wallet';
    private const META_ORIGINAL_TOTAL = 'crm_wallet_original_total';
    private const META_PROCESSED      = 'crm_wallet_processed';
    private const META_BREAKDOWN      = 'crm_wallet_payment_breakdown';
    private const META_BALANCE_BEFORE = 'crm_wallet_balance_before';
    private const META_BALANCE_AFTER  = 'crm_wallet_balance_after';
    private const META_GATEWAY_DUE    = 'crm_wallet_gateway_due';
    private const META_PLANNED_USE    = 'crm_wallet_planned_use';
    private const META_LINKED         = '_linked_post_id';
    private const CRON_HOOK           = 'crm_cancel_unpaid_wallet_orders';
    private const ENDPOINT_INCOME     = 'wallet-income';
    private const ENDPOINT_PAYMENTS   = 'wallet-payments';

    public function register(): void {
        add_action( 'init', [ $this, 'add_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'add_menu_item' ] );
        add_action( 'woocommerce_account_wallet_endpoint', [ $this, 'endpoint_content' ] );
        add_shortcode( 'crm_wallet', [ $this, 'shortcode' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT_INCOME . '_endpoint', [ $this, 'income_content' ] );
        add_action( 'woocommerce_account_' . self::ENDPOINT_PAYMENTS . '_endpoint', [ $this, 'payments_content' ] );
        add_shortcode( 'crm_wallet_income', [ $this, 'income_shortcode' ] );
        add_shortcode( 'crm_wallet_payments', [ $this, 'payments_shortcode' ] );

        add_action( 'woocommerce_order_status_completed', [ $this, 'credit_topup' ] );
        add_action( 'init', [ $this, 'schedule_cancellation' ] );
        add_action( self::CRON_HOOK, [ $this, 'cancel_unpaid_orders' ] );

        add_action( 'woocommerce_review_order_after_order_total', [ $this, 'checkout_checkbox' ] );
        add_action( 'woocommerce_cart_totals_after_order_total', [ $this, 'checkout_checkbox' ] );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'store_wallet_flag' ] );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_wallet_discount' ] );
        add_action( 'woocommerce_checkout_create_order', [ $this, 'save_used_wallet' ], 10, 2 );
        add_filter( 'woocommerce_order_needs_payment', [ $this, 'order_needs_payment' ], 10, 3 );
        add_action( 'woocommerce_payment_complete', [ $this, 'after_payment' ] );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'refund_wallet' ], 10 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'refund_wallet' ], 10 );

        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
        add_action( 'wp_ajax_crm_wallet_manager_table', [ $this, 'ajax_wallet_manager_table' ] );
    }

    public function add_endpoint(): void {
        add_rewrite_endpoint( 'wallet', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::ENDPOINT_INCOME, EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( self::ENDPOINT_PAYMENTS, EP_ROOT | EP_PAGES );
    }

    public function add_menu_item( array $items ): array {
        $items['wallet'] = 'کیف پول';
        $reordered       = [];
        foreach ( $items as $key => $label ) {
            $reordered[ $key ] = $label;
            if ( $key === 'wallet' ) {
                $reordered[ self::ENDPOINT_INCOME ]   = 'درآمدهای کیف پول';
                $reordered[ self::ENDPOINT_PAYMENTS ] = 'پرداخت‌های کیف پول';
            }
        }
        return $reordered;
    }

    public function endpoint_content(): void {
        echo $this->render_wallet_page();
    }

    public function shortcode(): string {
        return $this->render_wallet_page();
    }

    public function income_content(): void {
        echo $this->render_income_page();
    }

    public function payments_content(): void {
        echo $this->render_payments_page();
    }

    public function income_shortcode(): string {
        return $this->render_income_page();
    }

    public function payments_shortcode(): string {
        return $this->render_payments_page();
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

    public function schedule_cancellation(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        }
    }

    public function cancel_unpaid_orders(): void {
        if ( ! function_exists( 'wc_get_orders' ) ) {
            return;
        }
        $threshold = time() - ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
        $orders    = wc_get_orders( [
            'status'      => 'pending',
            'limit'       => -1,
            'meta_query'  => [ [ 'key' => 'wallet_topup', 'value' => 0, 'compare' => '>' ] ],
            'date_created' => '<=' . $threshold,
        ] );
        foreach ( $orders as $order ) {
            $created = $order->get_date_created();
            if ( $created && $created->getTimestamp() <= $threshold && $order->get_status() === 'pending' ) {
                $order->update_status( 'cancelled', 'Wallet topup not paid within one hour' );
            }
        }
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
        $use_wallet = false;

        $post_data = $_POST['post_data'] ?? null;
        if ( is_string( $post_data ) && $post_data !== '' ) {
            if ( function_exists( 'wp_unslash' ) ) {
                $post_data = wp_unslash( $post_data );
            }
            $parsed = [];
            parse_str( $post_data, $parsed );
            $use_wallet = ! empty( $parsed['crm_use_wallet'] );
        } elseif ( isset( $_POST['crm_use_wallet'] ) ) {
            $use_wallet = ! empty( $_POST['crm_use_wallet'] );
        }

        WC()->session->set( 'crm_use_wallet', $use_wallet );
        if ( ! $use_wallet ) {
            WC()->session->set( 'crm_wallet_use_amount', 0 );
            WC()->session->set( 'crm_wallet_cart_total', 0 );
        }
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
            WC()->session->set( 'crm_wallet_use_amount', 0 );
            WC()->session->set( 'crm_wallet_cart_total', 0 );
            return;
        }
        $total = (float) $cart->get_total( 'edit' );
        if ( $total <= 0 ) {
            WC()->session->set( 'crm_wallet_use_amount', 0 );
            WC()->session->set( 'crm_wallet_cart_total', 0 );
            return;
        }
        $use = min( $balance, $total );
        if ( $use <= 0 ) {
            WC()->session->set( 'crm_wallet_use_amount', 0 );
            WC()->session->set( 'crm_wallet_cart_total', 0 );
            return;
        }
        $cart->add_fee( 'کیف پول', -$use, false );
        WC()->session->set( 'crm_wallet_use_amount', $use );
        WC()->session->set( 'crm_wallet_cart_total', $total );
    }

    public function save_used_wallet( $order, $data ): void {
        $session_use   = (float) WC()->session->get( 'crm_wallet_use_amount' );
        $cart_total    = (float) WC()->session->get( 'crm_wallet_cart_total' );
        $user_id       = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
        $order_total   = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
        $use_wallet_ui = (bool) WC()->session->get( 'crm_use_wallet' );

        if ( $session_use <= 0 && $use_wallet_ui && $user_id > 0 ) {
            if ( $cart_total <= 0 && $order_total > 0 ) {
                $cart_total = $order_total;
                WC()->session->set( 'crm_wallet_cart_total', $cart_total );
            }
            $session_use = min( self::get_balance( $user_id ), $cart_total );
            WC()->session->set( 'crm_wallet_use_amount', $session_use );
        }

        if ( $session_use <= 0 ) {
            return;
        }

        $balance_before = $user_id > 0 ? self::get_balance( $user_id ) : 0.0;
        $original_total = $cart_total > 0 ? $cart_total : ( $order_total > 0 ? $order_total : $session_use );
        $planned_use    = min( $session_use, $balance_before, $original_total );
        $remaining_for_pg = max( 0.0, $original_total - $planned_use );

        if ( method_exists( $order, 'set_total' ) && abs( $order_total - $remaining_for_pg ) > 0.01 ) {
            $order->set_total( $remaining_for_pg );
        }

        $breakdown = [
            'wallet_balance_before' => $balance_before,
            'order_total_before'    => $original_total,
            'wallet_planned_use'    => $planned_use,
            'gateway_remaining'     => $remaining_for_pg,
        ];

        if ( method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( self::META_USED, $planned_use );
            $order->update_meta_data( self::META_PLANNED_USE, $planned_use );
            $order->update_meta_data( self::META_ORIGINAL_TOTAL, $original_total );
            $order->update_meta_data( self::META_PROCESSED, 'pending' );
            $order->update_meta_data( self::META_BREAKDOWN, $breakdown );
            $order->update_meta_data( self::META_BALANCE_BEFORE, $balance_before );
            $order->update_meta_data( self::META_GATEWAY_DUE, $remaining_for_pg );
        }

        if ( method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note( 'خلاصه پرداخت: مجموع ' . wc_price( $original_total ) . '، سهم کیف پول ' . wc_price( $planned_use ) . '، باقیمانده برای درگاه ' . wc_price( $remaining_for_pg ) );
        }

        if ( $remaining_for_pg <= 0.0 && $planned_use > 0 && $user_id > 0 ) {
            $this->finalize_wallet_only_payment( $order, $user_id, $planned_use, $original_total, $balance_before );
        }
    }

    public function order_needs_payment( bool $needs_payment, $order, array $valid_order_statuses ): bool {
        if ( ! $needs_payment || ! $order || ! method_exists( $order, 'get_meta' ) ) {
            return $needs_payment;
        }

        $planned_use = (float) $order->get_meta( self::META_PLANNED_USE );
        $gateway_due = (float) $order->get_meta( self::META_GATEWAY_DUE );

        if ( $planned_use > 0 && $gateway_due <= 0.0 ) {
            return false;
        }

        return $needs_payment;
    }

    public function after_payment( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $user_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
        $used    = method_exists( $order, 'get_meta' ) ? (float) $order->get_meta( self::META_USED ) : 0.0;
        if ( $user_id > 0 && $used > 0 ) {
            $settled = $this->settle_wallet_payment( $order, $user_id, $used );
            if ( $settled === false ) {
                return;
            }
        }
        if ( ! method_exists( $order, 'get_items' ) ) {
            return;
        }
        foreach ( $order->get_items() as $item ) {
            $post_id = (int) get_post_meta( $item->get_product_id(), self::META_LINKED, true );
            if ( ! $post_id ) {
                continue;
            }
            $ptype      = get_post_type( $post_id );
            $payout_key = '_' . $ptype . '_payouts';
            $payouts    = (array) get_post_meta( $post_id, $payout_key, true );
            $line_total = $item->get_total();
            foreach ( $payouts as $p ) {
                $targets = [];
                $rtype   = $p['recipient_type'] ?? 'user';
                if ( $rtype === 'predefined' ) {
                    $role = $p['role'] ?? '';
                    if ( $role ) {
                        $defs = \IMAOCustom\Plugin::get_payout_roles();
                        $def  = $defs[ $role ] ?? null;
                        if ( $def ) {
                            if ( ( $def['resolver'] ?? '' ) === 'user_meta' ) {
                                $meta_key = $def['meta_key'] ?? ( $role . '_id' );
                                $dynamic  = (int) get_user_meta( $user_id, $meta_key, true );
                                if ( $dynamic ) {
                                    $targets[] = $dynamic;
                                }
                            } else {
                                continue;
                            }
                        }
                    }
                } else {
                    $uid  = (int) ( $p['user_id'] ?? 0 );
                    $role = $p['role'] ?? '';
                    if ( $uid ) {
                        $targets[] = $uid;
                    } elseif ( $role ) {
                        $meta_key = $role . '_id';
                        $dynamic  = (int) get_user_meta( $user_id, $meta_key, true );
                        if ( $dynamic ) {
                            $targets[] = $dynamic;
                        }
                    }
                }
                foreach ( $targets as $dest ) {
                    $amt = ( $p['type'] === 'percent' ) ? $line_total * $p['value'] / 100 : (float) $p['value'];
                    if ( $amt <= 0 ) {
                        continue;
                    }
                    self::add_balance( $dest, $amt );
                    $label = ( $ptype === 'competition' ) ? 'مسابقه' : 'دوره';
                    self::add_log( $dest, $amt, 'درآمد از ' . $label . ' #' . $post_id . ' (سفارش ' . $order_id . ')' );
                }
            }
        }
    }

    /**
     * Settle wallet usage after a successful gateway payment.
     *
     * @param object $order    WooCommerce order instance.
     * @param int    $user_id  Customer identifier.
     * @param float  $requested Wallet amount reserved during checkout.
     *
     * @return float|false Returns the deducted wallet amount or false when the order should halt further processing.
     */
    private function settle_wallet_payment( $order, int $user_id, float $requested ) {
        $order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
        $status   = '';
        if ( method_exists( $order, 'get_meta' ) ) {
            $status = (string) $order->get_meta( self::META_PROCESSED );
        }
        if ( $status === 'completed' ) {
            return method_exists( $order, 'get_meta' ) ? (float) $order->get_meta( self::META_USED ) : $requested;
        }
        if ( $status === 'failed' ) {
            return false;
        }

        $original_total = method_exists( $order, 'get_meta' ) ? (float) $order->get_meta( self::META_ORIGINAL_TOTAL ) : 0.0;
        if ( $original_total <= 0 ) {
            $fallback_total = method_exists( $order, 'get_total' ) ? (float) $order->get_total() + $requested : $requested;
            $original_total = max( $requested, $fallback_total );
        }

        $balance_before = method_exists( $order, 'get_meta' ) ? (float) $order->get_meta( self::META_BALANCE_BEFORE ) : 0.0;
        if ( $balance_before <= 0 ) {
            $balance_before = self::get_balance( $user_id );
        }
        $planned_use = method_exists( $order, 'get_meta' ) ? (float) $order->get_meta( self::META_PLANNED_USE ) : $requested;

        $paid_amount = 0.0;
        if ( method_exists( $order, 'get_total_paid' ) ) {
            $paid_amount = (float) $order->get_total_paid();
        }
        if ( $paid_amount <= 0 && method_exists( $order, 'get_total' ) ) {
            $paid_amount = (float) $order->get_total();
        }

        $wallet_balance = self::get_balance( $user_id );
        $epsilon        = 0.01;
        if ( $wallet_balance + $paid_amount + $epsilon < $original_total ) {
            if ( $paid_amount > 0 ) {
                self::add_balance( $user_id, $paid_amount );
                $note = 'افزایش اعتبار به دلیل پرداخت ناکافی سفارش' . ( $order_id ? ' #' . $order_id : '' );
                self::add_log( $user_id, $paid_amount, $note );
            }
            $status_note = 'پرداخت ناکافی: مبلغ پرداخت شده به کیف پول اضافه شد.';
            if ( method_exists( $order, 'update_status' ) ) {
                $order->update_status( 'failed', $status_note );
            } elseif ( method_exists( $order, 'add_order_note' ) ) {
                $order->add_order_note( $status_note );
            }
            if ( method_exists( $order, 'delete_meta_data' ) ) {
                $order->delete_meta_data( self::META_USED );
            }
            if ( method_exists( $order, 'update_meta_data' ) ) {
                $order->update_meta_data( self::META_PROCESSED, 'failed' );
                $order->update_meta_data( self::META_ORIGINAL_TOTAL, $original_total );
            }
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
            return false;
        }

        $required = max( 0.0, $original_total - $paid_amount );
        $required = min( $required, $planned_use );
        $deduct   = min( $wallet_balance, $required );

        if ( $deduct > 0 ) {
            self::deduct_balance( $user_id, $deduct );
            $note = 'استفاده در سفارش' . ( $order_id ? ' #' . $order_id : '' );
            self::add_log( $user_id, -$deduct, $note );
        }

        $balance_after = self::get_balance( $user_id );
        $breakdown     = method_exists( $order, 'get_meta' ) ? (array) $order->get_meta( self::META_BREAKDOWN ) : [];
        $breakdown     = array_merge( $breakdown, [
            'wallet_balance_after' => $balance_after,
            'gateway_paid'         => $paid_amount,
            'wallet_deducted'      => $deduct,
        ] );

        if ( method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note( 'پرداخت ترکیبی: کیف پول ' . wc_price( $deduct ) . ' از موجودی ' . wc_price( $balance_before ) . '، مبلغ درگاه ' . wc_price( $paid_amount ) );
        }

        if ( method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( self::META_USED, $deduct );
            $order->update_meta_data( self::META_PROCESSED, 'completed' );
            $order->update_meta_data( self::META_ORIGINAL_TOTAL, $original_total );
            $order->update_meta_data( self::META_BALANCE_AFTER, $balance_after );
            $order->update_meta_data( self::META_BREAKDOWN, $breakdown );
            $order->update_meta_data( self::META_GATEWAY_DUE, max( 0.0, $original_total - $deduct - $paid_amount ) );
        }
        if ( method_exists( $order, 'save' ) ) {
            $order->save();
        }

        return $deduct;
    }

    private function finalize_wallet_only_payment( $order, int $user_id, float $planned_use, float $original_total, float $balance_before ): void {
        $available = self::get_balance( $user_id );
        $deduct    = min( $planned_use, $available, $original_total );
        $balance_after = $available;

        if ( $deduct > 0 ) {
            self::deduct_balance( $user_id, $deduct );
            $balance_after = self::get_balance( $user_id );
            self::add_log( $user_id, -$deduct, 'پرداخت کامل با کیف پول' );
        }

        $breakdown = [
            'wallet_balance_before' => $balance_before,
            'order_total_before'    => $original_total,
            'wallet_planned_use'    => $planned_use,
            'gateway_remaining'     => 0.0,
            'wallet_deducted'       => $deduct,
            'wallet_balance_after'  => $balance_after,
            'gateway_paid'          => 0.0,
        ];

        if ( method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note( 'پرداخت کامل با کیف پول: کسر ' . wc_price( $deduct ) . ' از موجودی ' . wc_price( $balance_before ) );
        }

        if ( method_exists( $order, 'set_total' ) ) {
            $order->set_total( 0 );
        }

        if ( method_exists( $order, 'update_meta_data' ) ) {
            $order->update_meta_data( self::META_USED, $deduct );
            $order->update_meta_data( self::META_PROCESSED, 'completed' );
            $order->update_meta_data( self::META_ORIGINAL_TOTAL, $original_total );
            $order->update_meta_data( self::META_BREAKDOWN, $breakdown );
            $order->update_meta_data( self::META_BALANCE_AFTER, $balance_after );
            $order->update_meta_data( self::META_GATEWAY_DUE, 0 );
        }

        if ( method_exists( $order, 'payment_complete' ) ) {
            $order->payment_complete();
        }
        if ( method_exists( $order, 'save' ) ) {
            $order->save();
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
        wp_enqueue_style( 'dt-css', $url . 'assets/css/jquery.dataTables.min.css' );
        wp_enqueue_style( 'dt-buttons-css', 'https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css', [ 'dt-css' ], '2.4.2' );

        wp_enqueue_script( 'dt-js', $url . 'assets/js/jquery.dataTables.min.js', [ 'jquery' ], null, true );
        wp_enqueue_script( 'dt-buttons', 'https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js', [ 'dt-js' ], '2.4.2', true );
        wp_enqueue_script( 'dt-jszip', 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js', [ 'dt-buttons' ], '3.10.1', true );
        wp_enqueue_script( 'dt-buttons-html5', 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js', [ 'dt-jszip' ], '2.4.2', true );
        wp_enqueue_script( 'dt-buttons-print', 'https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js', [ 'dt-buttons' ], '2.4.2', true );

        wp_register_script( 'crm-wallet-manager', $url . 'assets/js/wallet-manager.js', [ 'jquery', 'dt-buttons-print' ], '1.0.0', true );
        wp_localize_script( 'crm-wallet-manager', 'crmWalletManager', [
            'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
            'ajaxNonce' => wp_create_nonce( 'crm_wallet_manager_table' ),
        ] );
        wp_enqueue_script( 'crm-wallet-manager' );
    }

    public function wallet_manager_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        if ( isset( $_POST['crm_wallet_adj'] ) ) {
            check_admin_referer( 'crm_wallet_adj_nonce' );
            $uid  = (int) $_POST['user'];
            $amt  = (float) $_POST['amount'];
            $note = sanitize_text_field( $_POST['memo'] );
            $act  = sanitize_text_field( $_POST['action'] );
            $user = get_user_by( 'id', $uid );
            if ( ! $user ) {
                echo '<div class="notice notice-error"><p>کاربر یافت نشد.</p></div>';
            } else {
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
        }
        echo '<div class="wrap"><h1>مدیریت کیف پول کاربران</h1>';
        echo '<table id="crm-wallet-table" class="widefat striped nowrap" style="width:100%">'
            . '<thead><tr><th>ردیف</th><th>نام</th><th>نام خانوادگی</th><th>موجودی</th><th>شماره کارت</th><th>شماره شبا</th><th>مبلغ</th><th>پرداخت بابت</th><th>عملیات</th></tr></thead>'
            . '<tbody></tbody>'
            . '</table></div>';
    }

    public function ajax_wallet_manager_table(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        check_ajax_referer( 'crm_wallet_manager_table' );

        $draw   = isset( $_POST['draw'] ) ? (int) $_POST['draw'] : 0;
        $start  = isset( $_POST['start'] ) ? max( 0, (int) $_POST['start'] ) : 0;
        $length = isset( $_POST['length'] ) ? (int) $_POST['length'] : 50;
        $length = $length > 0 ? $length : 50;

        $search_value = isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
        $order        = $_POST['order'][0] ?? [ 'column' => 3, 'dir' => 'desc' ];
        $order_col    = isset( $order['column'] ) ? (int) $order['column'] : 3;
        $order_dir    = ( isset( $order['dir'] ) && strtolower( $order['dir'] ) === 'asc' ) ? 'ASC' : 'DESC';

        $args = [
            'number'      => $length,
            'offset'      => $start,
            'count_total' => true,
            'fields'      => [ 'ID', 'display_name', 'user_email' ],
            'orderby'     => 'display_name',
            'order'       => $order_dir,
        ];

        if ( $search_value !== '' ) {
            $args['search']          = '*' . $search_value . '*';
            $args['search_columns']  = [ 'user_email', 'user_nicename', 'display_name' ];
        }

        if ( $order_col === 3 ) {
            $args['orderby']   = 'meta_value_num';
            $args['meta_key']  = WalletHelper::get_meta_key();
            $args['meta_type'] = 'NUMERIC';
        }

        $query          = new \WP_User_Query( $args );
        $total_users    = (int) $query->get_total();
        $filtered_total = $total_users;
        $users          = $query->get_results();
        $nonce_field_tpl = function (): string {
            return wp_nonce_field( 'crm_wallet_adj_nonce', '_wpnonce', true, false );
        };

        $rows = [];
        foreach ( $users as $index => $user ) {
            $balance    = self::get_balance( $user->ID );
            $form_id    = 'crm-wallet-form-' . $user->ID;
            $amount     = '<input type="number" class="crm-wallet-amount" name="amount" form="' . esc_attr( $form_id ) . '" step="0.01" required style="width:120px">';
            $memo       = '<input type="text" class="crm-wallet-memo" name="memo" form="' . esc_attr( $form_id ) . '" style="width:100%">';
            $actions    = '<form method="post" id="' . esc_attr( $form_id ) . '" class="crm-wallet-form">'
                        . $nonce_field_tpl()
                        . '<input type="hidden" name="user" value="' . absint( $user->ID ) . '">'
                        . '<input type="hidden" name="crm_wallet_adj" value="1">'
                        . '<button class="button" name="action" value="add">افزایش</button> '
                        . '<button class="button" name="action" value="sub">کاهش</button> '
                        . '<button class="button" name="action" value="set">تنظیم موجودی</button>'
                        . '</form>';

            $first_name = get_user_meta( $user->ID, 'first_name', true );
            $last_name  = get_user_meta( $user->ID, 'last_name', true );
            $card       = get_user_meta( $user->ID, 'card_number', true );
            $iban       = get_user_meta( $user->ID, 'iban', true );

            $rows[] = [
                'row_number'  => $start + $index + 1,
                'first_name'  => esc_html( $first_name ?: '-' ),
                'last_name'   => esc_html( $last_name ?: '-' ),
                'balance'     => wc_price( $balance ),
                'balance_raw' => $balance,
                'card_number' => esc_html( $card ?: '-' ),
                'iban'        => esc_html( $iban ?: '-' ),
                'amount'      => $amount,
                'memo'        => $memo,
                'actions'     => $actions,
            ];
        }

        wp_send_json( [
            'draw'            => $draw,
            'recordsTotal'    => $total_users,
            'recordsFiltered' => $filtered_total,
            'data'            => $rows,
        ] );
    }

    /**
     * @return array<int,array{date:string,amount:float,note:string}>
     */
    private function get_wallet_log( int $user_id ): array {
        $logs = (array) get_user_meta( $user_id, self::META_LOG, true );
        usort(
            $logs,
            static function ( $a, $b ) {
                return strtotime( $b['date'] ?? '' ) <=> strtotime( $a['date'] ?? '' );
            }
        );
        return $logs;
    }

    private function render_income_page(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ کیف پول ابتدا وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $logs    = array_filter(
            $this->get_wallet_log( $user_id ),
            static fn( $row ) => (float) ( $row['amount'] ?? 0 ) > 0
        );
        return $this->render_log_table(
            $logs,
            'فهرست درآمدهای کیف پول',
            'تراکنش مثبتی یافت نشد.'
        );
    }

    private function render_payments_page(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ کیف پول ابتدا وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $logs    = array_filter(
            $this->get_wallet_log( $user_id ),
            static fn( $row ) => (float) ( $row['amount'] ?? 0 ) < 0
        );
        return $this->render_log_table(
            $logs,
            'پرداخت‌های انجام‌شده با کیف پول',
            'پرداختی با کیف پول ثبت نشده است.'
        );
    }

    /**
     * @param array<int,array{date:string,amount:float,note:string}> $logs
     */
    private function render_log_table( array $logs, string $title, string $empty_message ): string {
        ob_start();
        ?>
        <div id="wallet-box">
            <h4 class="wallet-section-title"><?php echo esc_html( $title ); ?></h4>
            <?php if ( empty( $logs ) ) : ?>
                <p class="wallet-no-orders"><?php echo esc_html( $empty_message ); ?></p>
            <?php else : ?>
                <table class="wallet-table log-table striped">
                    <thead>
                        <tr>
                            <th>تاریخ</th>
                            <th>مبلغ</th>
                            <th>توضیحات</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ( $logs as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( date_i18n( 'Y/m/d H:i', strtotime( $row['date'] ?? '' ) ) ); ?></td>
                            <td><?php echo wc_price( (float) $row['amount'] ); ?></td>
                            <td><?php echo esc_html( (string) ( $row['note'] ?? '' ) ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_wallet_page(): string {
        if ( ! is_user_logged_in() ) {
            return '<p style="text-align:center;color:#c00;">برای مشاهدهٔ کیف پول ابتدا وارد شوید.</p>';
        }
        $user_id = get_current_user_id();
        $balance = self::get_balance( $user_id );
        if ( isset( $_POST['wallet_charge'], $_POST['amount'] ) ) {
            $amount     = max( 0, (float) $_POST['amount'] );
            $min_charge = Price::from_rial( 10000 );
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
                <table class="wallet-table striped">
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
            $logs = $this->get_wallet_log( $user_id );
            if ( $logs ) : ?>
                <h4 class="wallet-section-title">جدول تراکنش های سامانه</h4>
                <table class="wallet-table log-table striped">
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
