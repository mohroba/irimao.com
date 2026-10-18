<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\Date;
use Morilog\Jalali\Jalalian;
use RuntimeException;

/** Monthly tuition uses immutable checkout rules and an atomic payment ledger. */
class MonthlyTuition {
    public const ITEM_SNAPSHOT = '_imao_class_snapshot';
    private const VERSION = '202610180001';

    public function register(): void {
        add_action( 'init', [ $this, 'install' ], 4 );
        add_action( 'admin_post_imao_pay_monthly_class', [ $this, 'start_checkout' ] );
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_cart' ], 20, 6 );
        add_action( 'woocommerce_check_cart_items', [ $this, 'check_cart' ] );
        add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'snapshot_item' ], 20, 4 );
        add_action( 'woocommerce_checkout_order_created', [ $this, 'prepare_order' ], 20 );
        add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'prepare_order' ], 20 );
        add_action( 'woocommerce_before_pay_action', [ $this, 'prepare_order' ], 20 );
        add_action( 'woocommerce_payment_complete', [ $this, 'process_order' ], 30 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'process_order' ], 30 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'process_order' ], 30 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'reverse_order' ], 30 );
        add_action( 'woocommerce_order_refunded', [ $this, 'reverse_order' ], 30 );
        add_action( 'imao_retry_monthly_tuition', [ $this, 'process_order' ], 10, 2 );
        add_action( 'imao_retry_monthly_tuition_refund', [ $this, 'reverse_order' ], 10, 2 );
        add_filter( 'woocommerce_order_actions', [ $this, 'order_actions' ] );
        add_action( 'woocommerce_order_action_imao_retry_tuition', [ $this, 'retry_order' ] );
        add_shortcode( 'imao_class_due', [ $this, 'due_shortcode' ] );
        add_shortcode( 'imao_class_info', [ $this, 'info_shortcode' ] );
        add_shortcode( 'imao_class_payments', [ $this, 'payments_shortcode' ] );
    }

    /** Additive, versioned schema; never modifies the existing payout tables. */
    public function install(): void {
        if ( get_option( 'imao_class_tuition_db_version' ) === self::VERSION ) {
            return;
        }
        if ( ( new \IMAOCustom\Migrations\CreateMonthlyTuition202610180001() )->up() ) {
            update_option( 'imao_class_tuition_db_version', self::VERSION, false );
        }
    }

    public static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'imao_class_tuition';
    }

    /** Clamp at the last Jalali day instead of overflowing into another month. */
    public static function next_due_date( string $paid_at ): string {
        $date = Jalalian::fromDateTime( new \DateTimeImmutable( $paid_at, wp_timezone() ) );
        $month = $date->getMonth() === 12 ? 1 : $date->getMonth() + 1;
        $year = $date->getYear() + ( $month === 1 ? 1 : 0 );
        $first = new Jalalian( $year, $month, 1, 0, 0, 0, wp_timezone() );
        $next = new Jalalian( $year, $month, min( $date->getDay(), $first->getMonthDays() ), 0, 0, 0, wp_timezone() );
        return $next->toCarbon()->format( 'Y-m-d' );
    }

    public static function due_date( int $user_id, int $class_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            'SELECT due_date FROM ' . self::table() . " WHERE user_id=%d AND class_id=%d AND status IN ('paid','partial_refund') ORDER BY paid_at DESC,id DESC LIMIT 1",
            $user_id, $class_id
        ) );
    }

    public static function days_remaining( int $user_id, int $class_id ): ?int {
        $due = self::due_date( $user_id, $class_id );
        if ( $due === '' ) {
            return null;
        }
        $today = new \DateTimeImmutable( current_time( 'Y-m-d' ), wp_timezone() );
        return (int) $today->diff( new \DateTimeImmutable( $due, wp_timezone() ) )->format( '%r%a' );
    }

    public static function split_amount( float $amount, array $shares ): array {
        $sum = 0;
        $basis = [];
        foreach ( [ 'referrer', 'coach', 'club' ] as $role ) {
            $value = $shares[ $role ] ?? null;
            if ( ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value <= 0 || (float) $value > 100 ) {
                throw new RuntimeException( 'درصد هر سه ذی‌نفع باید بیشتر از صفر باشد.' );
            }
            $basis[ $role ] = (int) round( (float) $value * 100 );
            $sum += $basis[ $role ];
        }
        if ( $sum !== 10000 || ! is_finite( $amount ) || $amount < 0 ) {
            throw new RuntimeException( 'مجموع درصدهای تسهیم باید دقیقاً ۱۰۰ باشد.' );
        }
        $cents = (int) round( $amount * 100 );
        $result = [];
        $remaining = $cents;
        foreach ( [ 'referrer', 'coach' ] as $role ) {
            $part = (int) floor( $cents * $basis[ $role ] / 10000 );
            $result[ $role ] = $part / 100;
            $remaining -= $part;
        }
        $result['club'] = $remaining / 100;
        return $result;
    }

    public static function checkout_snapshot( int $class_id, int $user_id ): array {
        $class = get_post( $class_id );
        $coach = (int) get_post_meta( $class_id, '_class_coach_id', true );
        $club = (int) get_post_meta( $class_id, '_class_club_id', true );
        if ( ! $class || $class->post_type !== 'imao_monthly_class' || $class->post_status !== 'publish'
            || get_post_meta( $class_id, '_class_approved', true ) !== 'yes'
            || $user_id <= 0 || $coach <= 0 || (int) get_user_meta( $user_id, 'coach_id', true ) !== $coach ) {
            throw new RuntimeException( 'این کلاس تأیید نشده یا متعلق به مربی انتخاب‌شده شما نیست.' );
        }
        $club_post = get_post( $club );
        if ( ! \IMAOCustom\Helpers\UserRoles::has( get_userdata( $coach ), 'coach' )
            || get_user_meta( $coach, 'identity_verified_professional', true ) !== 'approved'
            || ! $club_post || (int) $club_post->post_author !== $coach ) {
            throw new RuntimeException( 'مربی یا باشگاه کلاس دیگر شرایط تأییدشده را ندارد.' );
        }
        $recipients = [
            'referrer' => (int) get_user_meta( $user_id, 'referrer_id', true ),
            'coach' => $coach,
            'club' => $club_post && $club_post->post_type === 'club_application' && $club_post->post_status === 'publish' ? (int) $club_post->post_author : 0,
        ];
        foreach ( $recipients as $recipient ) {
            if ( $recipient <= 0 || ! get_userdata( $recipient ) ) {
                throw new RuntimeException( 'معرف، مربی و صاحب حساب باشگاه باید قبل از پرداخت مشخص باشند؛ با مدیر تماس بگیرید.' );
            }
        }
        $shares = (array) get_post_meta( $class_id, '_class_shares', true );
        self::split_amount( 100, $shares );
        return [ 'class_id' => $class_id, 'user_id' => $user_id, 'club_id' => $club, 'recipients' => $recipients, 'shares' => $shares ];
    }

    private static function class_for_product( int $product_id ): int {
        $class_id = (int) get_post_meta( $product_id, '_imao_monthly_class_id', true );
        if ( ! $class_id ) {
            $linked = (int) get_post_meta( $product_id, '_linked_post_id', true );
            $class_id = get_post_type( $linked ) === 'imao_monthly_class' ? $linked : 0;
        }
        return $class_id;
    }

    public function start_checkout(): void {
        if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' || ! is_user_logged_in() ) {
            wp_die( 'ابتدا وارد حساب خود شوید.' );
        }
        $id = absint( $_POST['class_id'] ?? 0 );
        check_admin_referer( 'imao_pay_monthly_class_' . $id, 'imao_monthly_class_nonce' );
        try {
            self::checkout_snapshot( $id, get_current_user_id() );
            // WooCommerce does not initialize the storefront cart on admin-post requests.
            if ( ! WC()->cart ) {
                wc_load_cart();
            }
            if ( WC()->session ) {
                WC()->session->set_customer_session_cookie( true );
            }
            $product = (int) get_post_meta( $id, '_class_product_id', true );
            if ( $product <= 0 || ! WC()->cart || ! WC()->cart->add_to_cart( $product, 1 ) ) {
                throw new RuntimeException( 'افزودن شهریه به سبد خرید ممکن نشد.' );
            }
            wp_safe_redirect( wc_get_checkout_url() );
            exit;
        } catch ( \Throwable $e ) {
            wp_die( esc_html( $e->getMessage() ) );
        }
    }

    public function validate_cart( $valid, $product_id, $quantity, $variation_id = 0, $variations = [], $data = [] ): bool {
        $class = self::class_for_product( (int) $product_id );
        if ( ! $class ) {
            return (bool) $valid;
        }
        try {
            self::checkout_snapshot( $class, get_current_user_id() );
            if ( (int) $quantity !== 1 ) {
                throw new RuntimeException( 'هر بار فقط یک شهریه ماهانه قابل پرداخت است.' );
            }
        } catch ( \Throwable $e ) {
            wc_add_notice( $e->getMessage(), 'error' );
            return false;
        }
        return (bool) $valid;
    }

    public function check_cart(): void {
        foreach ( WC()->cart->get_cart() as $entry ) {
            $this->validate_cart( true, $entry['product_id'], $entry['quantity'] );
        }
    }

    public function snapshot_item( $item, $key, $values, $order ): void {
        $class = self::class_for_product( (int) $item->get_product_id() );
        if ( ! $class ) {
            return;
        }
        if ( (int) $item->get_quantity() !== 1 ) {
            throw new RuntimeException( 'تعداد شهریه ماهانه معتبر نیست.' );
        }
        $item->add_meta_data( self::ITEM_SNAPSHOT, self::checkout_snapshot( $class, (int) $order->get_customer_id() ), true );
    }

    /** Also covers block checkout and administrator-created pay-for-order links. */
    public function prepare_order( $order ): void {
        if ( is_numeric( $order ) ) {
            $order = wc_get_order( (int) $order );
        }
        if ( ! $order || $order->is_paid() ) {
            return;
        }
        foreach ( $order->get_items() as $item ) {
            $class = self::class_for_product( (int) $item->get_product_id() );
            if ( ! $class ) {
                continue;
            }
            if ( (int) $item->get_quantity() !== 1 ) {
                throw new RuntimeException( 'تعداد شهریه ماهانه معتبر نیست.' );
            }
            // Revalidate eligibility but preserve the already agreed recipients and percentages.
            $snapshot = self::checkout_snapshot( $class, (int) $order->get_customer_id() );
            if ( ! is_array( $item->get_meta( self::ITEM_SNAPSHOT, true ) ) ) {
                $item->add_meta_data( self::ITEM_SNAPSHOT, $snapshot, true );
                $item->save();
            }
        }
    }

    public function process_order( int $order_id, int $attempt = 0 ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! $order->is_paid() || ! $order->get_customer_id() ) {
            return;
        }
        foreach ( $order->get_items() as $item ) {
            $snapshot = $item->get_meta( self::ITEM_SNAPSHOT, true );
            if ( ! is_array( $snapshot ) || empty( $snapshot['class_id'] ) ) {
                continue;
            }
            try {
                $this->settle_item( $order, $item, $snapshot );
            } catch ( \Throwable $error ) {
                $order->add_order_note( 'خطا در تسهیم شهریه (قابل تلاش مجدد): ' . $error->getMessage() );
                wc_get_logger()->error( $error->getMessage(), [ 'source' => 'imao-monthly-tuition', 'order_id' => $order_id ] );
                if ( $attempt < 5 && ! wp_next_scheduled( 'imao_retry_monthly_tuition', [ $order_id, $attempt + 1 ] ) ) {
                    wp_schedule_single_event( time() + 300 * ( $attempt + 1 ), 'imao_retry_monthly_tuition', [ $order_id, $attempt + 1 ] );
                }
            }
        }
    }

    public function order_actions( array $actions ): array {
        $actions['imao_retry_tuition'] = 'تلاش مجدد تسهیم شهریه ماهانه';
        return $actions;
    }

    public function retry_order( $order ): void {
        if ( current_user_can( 'manage_woocommerce' ) && $order ) {
            $this->process_order( (int) $order->get_id() );
            $this->reverse_order( (int) $order->get_id() );
        }
    }

    private function settle_item( $order, $item, array $snapshot ): void {
        global $wpdb;
        if ( (int) ( $snapshot['user_id'] ?? 0 ) !== (int) $order->get_customer_id() ) {
            throw new RuntimeException( 'شناسه پرداخت‌کننده با اطلاعات شهریه مطابقت ندارد.' );
        }
        $amount = round( max( 0, (float) $item->get_total() ), 2 );
        $snapshot['amounts'] = self::split_amount( $amount, $snapshot['shares'] ?? [] );
        $refunded = min( $amount, abs( (float) $order->get_total_refunded_for_item( (int) $item->get_id() ) ) );
        $snapshot['credited'] = self::split_amount( max( 0, $amount - $refunded ), $snapshot['shares'] );
        $snapshot['refunded_amount'] = $refunded;
        $paid = $order->get_date_paid();
        $paid_at = $paid ? $paid->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
        $recipients = array_map( 'intval', $snapshot['recipients'] ?? [] );
        if ( array_diff( [ 'referrer', 'coach', 'club' ], array_keys( $recipients ) ) || min( $recipients ) <= 0 ) {
            throw new RuntimeException( 'گیرندگان شهریه معتبر نیستند.' );
        }
        $this->transaction( $recipients, function () use ( $wpdb, $order, $item, $snapshot, $paid_at, $amount ): void {
            $table = self::table();
            if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE order_item_id=%d FOR UPDATE", $item->get_id() ) ) ) {
                return;
            }
            $this->must( $wpdb->insert( $table, [
                'order_id' => $order->get_id(), 'order_item_id' => $item->get_id(),
                'class_id' => $snapshot['class_id'], 'user_id' => $order->get_customer_id(),
                'paid_at' => $paid_at, 'due_date' => self::next_due_date( $paid_at ),
                'amount' => $amount, 'snapshot' => wp_json_encode( $snapshot ),
                'status' => $snapshot['refunded_amount'] > 0 ? ( $snapshot['refunded_amount'] >= $amount ? 'reversed' : 'partial_refund' ) : 'paid',
            ] ) );
            foreach ( $snapshot['credited'] as $role => $value ) {
                $this->wallet_delta( (int) $snapshot['recipients'][ $role ], $value, $order->get_id(), $item->get_id(), $role );
            }
        } );
    }

    /** Partial refunds reverse only the matching fraction, using the original recipients. */
    public function reverse_order( int $order_id, int $attempt = 0 ): void {
        global $wpdb;
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        // Recover a payment whose first settlement failed before the refund arrived.
        if ( $order->is_paid() ) {
            $this->process_order( $order_id );
        }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE order_id=%d', $order_id ) );
        foreach ( $rows as $row ) {
            $snapshot = json_decode( $row->snapshot, true );
            if ( ! is_array( $snapshot ) ) {
                continue;
            }
            try {
                $this->transaction( $snapshot['recipients'], function () use ( $wpdb, $order, $row ): void {
                    $table = self::table();
                    $locked = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d FOR UPDATE", $row->id ) );
                    if ( ! $locked || $wpdb->last_error !== '' ) {
                        throw new RuntimeException( 'خواندن دفتر شهریه برای برگشت وجه ناموفق بود.' );
                    }
                    $snapshot = json_decode( $locked->snapshot, true );
                    $refunded = $order->has_status( 'refunded' ) ? (float) $locked->amount : abs( (float) $order->get_total_refunded_for_item( (int) $row->order_item_id ) );
                    $remaining = max( 0, (float) $locked->amount - $refunded );
                    $target = self::split_amount( $remaining, $snapshot['shares'] );
                    foreach ( $target as $role => $value ) {
                        $delta = round( $value - (float) $snapshot['credited'][ $role ], 2 );
                        if ( $delta !== 0.0 ) {
                            $this->wallet_delta( (int) $snapshot['recipients'][ $role ], $delta, $order->get_id(), (int) $row->order_item_id, $role );
                        }
                    }
                    $snapshot['credited'] = $target;
                    $snapshot['refunded_amount'] = $refunded;
                    $this->must( $wpdb->update( $table, [
                        'snapshot' => wp_json_encode( $snapshot ), 'status' => $remaining > 0 ? 'partial_refund' : 'reversed',
                    ], [ 'id' => $row->id ] ) );
                } );
            } catch ( \Throwable $e ) {
                $order->add_order_note( 'خطای برگشت تسهیم شهریه: ' . $e->getMessage() );
                wc_get_logger()->error( $e->getMessage(), [ 'source' => 'imao-monthly-tuition', 'order_id' => $order_id ] );
                if ( $attempt < 5 && ! wp_next_scheduled( 'imao_retry_monthly_tuition_refund', [ $order_id, $attempt + 1 ] ) ) {
                    wp_schedule_single_event( time() + 300 * ( $attempt + 1 ), 'imao_retry_monthly_tuition_refund', [ $order_id, $attempt + 1 ] );
                }
            }
        }
    }

    private function must( $result ): void {
        if ( $result === false ) {
            throw new RuntimeException( 'ثبت تراکنش شهریه در پایگاه داده ناموفق بود.' );
        }
    }

    private function transaction( array $recipients, callable $callback ): void {
        global $wpdb;
        $ids = array_unique( array_map( 'intval', array_values( $recipients ) ) );
        sort( $ids, SORT_NUMERIC );
        foreach ( [ $wpdb->users, $wpdb->usermeta, self::table() ] as $table ) {
            $engine = $wpdb->get_var( $wpdb->prepare( 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table ) );
            if ( strtoupper( (string) $engine ) !== 'INNODB' ) {
                throw new RuntimeException( 'تسهیم اتمیک نیازمند موتور InnoDB برای جداول کاربران و شهریه است.' );
            }
        }
        $this->must( $wpdb->query( 'START TRANSACTION' ) );
        try {
            foreach ( $ids as $id ) {
                if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE ID=%d FOR UPDATE", $id ) ) ) {
                    throw new RuntimeException( 'حساب یکی از ذی‌نفعان حذف شده است.' );
                }
            }
            $callback();
            $this->must( $wpdb->query( 'COMMIT' ) );
        } catch ( \Throwable $e ) {
            $wpdb->query( 'ROLLBACK' );
            throw $e;
        } finally {
            foreach ( $ids as $id ) {
                wp_cache_delete( $id, 'user_meta' );
            }
        }
    }

    /** Read database values under locks, avoiding stale WordPress metadata caches. */
    private function wallet_delta( int $user_id, float $delta, int $order_id, int $item_id, string $role ): void {
        global $wpdb;
        foreach ( [ '_wallet_balance', 'crm_wallet_log' ] as $key ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT umeta_id,meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key=%s ORDER BY umeta_id FOR UPDATE", $user_id, $key
            ) );
            if ( ! is_array( $rows ) || $wpdb->last_error !== '' ) {
                throw new RuntimeException( 'خواندن اطلاعات کیف پول ناموفق بود.' );
            }
            $value = $rows ? maybe_unserialize( $rows[0]->meta_value ) : null;
            if ( $key === '_wallet_balance' ) {
                // A negative balance records recoverable refund debt instead of losing money.
                $new = round( (float) $value + $delta, 2 );
            } else {
                $new = is_array( $value ) ? $value : [];
                $new[] = [ 'date' => current_time( 'mysql' ), 'amount' => $delta,
                    'note' => sprintf( 'تسهیم شهریه، سفارش #%d، قلم #%d (%s)', $order_id, $item_id, $role ),
                    'order_id' => $order_id, 'order_item_id' => $item_id, 'role' => $role ];
            }
            if ( $rows ) {
                $this->must( $wpdb->update( $wpdb->usermeta, [ 'meta_value' => maybe_serialize( $new ) ], [ 'user_id' => $user_id, 'meta_key' => $key ] ) );
            } else {
                $this->must( $wpdb->insert( $wpdb->usermeta, [ 'user_id' => $user_id, 'meta_key' => $key, 'meta_value' => maybe_serialize( $new ) ] ) );
            }
        }
    }

    public function due_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'class_id' => 0, 'field' => 'days' ], $atts );
        if ( ! is_user_logged_in() ) {
            return '';
        }
        $id = absint( $atts['class_id'] );
        if ( $atts['field'] === 'date' ) {
            return esc_html( Date::to_jalali( self::due_date( get_current_user_id(), $id ), 'Y/m/d' ) );
        }
        $days = self::days_remaining( get_current_user_id(), $id );
        return $days === null ? '' : (string) $days;
    }

    public function info_shortcode( $atts = [] ): string {
        $atts = shortcode_atts( [ 'class_id' => 0, 'field' => 'title' ], $atts );
        $id = absint( $atts['class_id'] );
        $class = get_post( $id );
        if ( ! is_user_logged_in() || ! $class || $class->post_type !== 'imao_monthly_class'
            || $class->post_status !== 'publish' || get_post_meta( $id, '_class_approved', true ) !== 'yes'
            || (int) get_post_meta( $id, '_class_coach_id', true ) !== (int) get_user_meta( get_current_user_id(), 'coach_id', true ) ) {
            return '';
        }
        switch ( $atts['field'] ) {
            case 'title': return esc_html( get_the_title( $id ) );
            case 'fee': return esc_html( (string) get_post_meta( $id, '_class_fee', true ) );
            case 'club': return esc_html( get_the_title( (int) get_post_meta( $id, '_class_club_id', true ) ) );
            case 'coach':
                $coach = get_userdata( (int) get_post_meta( $id, '_class_coach_id', true ) );
                return $coach ? esc_html( $coach->display_name ) : '';
            default: return '';
        }
    }

    /** Only the signed-in student's own tuition history is exposed. */
    public function payments_shortcode( $atts = [] ): string {
        if ( ! is_user_logged_in() ) {
            return '';
        }
        global $wpdb;
        $atts = shortcode_atts( [ 'class_id' => 0 ], $atts );
        $where = $wpdb->prepare( ' WHERE user_id=%d', get_current_user_id() );
        if ( absint( $atts['class_id'] ) ) {
            $where .= $wpdb->prepare( ' AND class_id=%d', absint( $atts['class_id'] ) );
        }
        $page = isset( $_GET['tuition_page'] ) && is_scalar( $_GET['tuition_page'] ) ? max( 1, absint( $_GET['tuition_page'] ) ) : 1;
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . $where );
        $rows = $wpdb->get_results( 'SELECT order_id,class_id,paid_at,due_date,amount,status FROM ' . self::table() . $where
            . $wpdb->prepare( ' ORDER BY paid_at DESC,id DESC LIMIT 20 OFFSET %d', ( $page - 1 ) * 20 ) );
        $html = '<div class="imao-monthly imao-class-payments" dir="rtl"><div class="crm-course-title">سوابق شهریه</div><div class="imao-class-table-scroll"><table class="shop_table striped"><thead><tr><th>سفارش</th><th>کلاس</th><th>پرداخت</th><th>سررسید</th><th>مبلغ</th><th>وضعیت</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $status = [ 'paid' => 'پرداخت‌شده', 'partial_refund' => 'بازپرداخت جزئی', 'reversed' => 'برگشت‌خورده' ][ $row->status ] ?? $row->status;
            $html .= '<tr><td>#' . (int) $row->order_id . '</td><td>' . esc_html( get_the_title( (int) $row->class_id ) ) . '</td><td>'
                . esc_html( Date::to_jalali( $row->paid_at ) ) . '</td><td>' . esc_html( Date::to_jalali( $row->due_date, 'Y/m/d' ) )
                . '</td><td>' . wp_kses_post( wc_price( $row->amount ) ) . '</td><td>' . esc_html( $status ) . '</td></tr>';
        }
        if ( ! $rows ) {
            $html .= '<tr><td colspan="6">هنوز شهریه‌ای پرداخت نشده است.</td></tr>';
        }
        $html .= '</tbody></table></div><nav class="imao-class-pagination" aria-label="صفحات سوابق شهریه">';
        for ( $number = max( 1, $page - 2 ); $number <= min( (int) ceil( $total / 20 ), $page + 2 ); ++$number ) {
            $html .= '<a href="' . esc_url( add_query_arg( 'tuition_page', $number ) ) . '">' . $number . '</a> ';
        }
        return $html . '</nav></div>';
    }
}
