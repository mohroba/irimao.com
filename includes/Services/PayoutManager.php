<?php

namespace IMAOCustom\Services;

use IMAOCustom\Helpers\RepresentativeManager;
use IMAOCustom\Helpers\UserMeta;
use IMAOCustom\Services\Endpoints\Wallet;

class PayoutManager {
    private const DB_VERSION = '1';
    private const DB_OPTION  = 'imao_payout_audit_db_version';
    private const META_LINKED = '_linked_post_id';

    private string $table = '';

    public function register(): void {
        add_action( 'init', [ $this, 'maybe_install' ], 5 );
        add_action( 'woocommerce_payment_complete', [ $this, 'process_order' ], 20 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'reverse_order' ], 20 );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'reverse_order' ], 20 );
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
    }

    public function maybe_install(): void {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = $this->table();
        $sql = "CREATE TABLE {$table} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            transaction_key char(64) NOT NULL,
            order_id bigint unsigned NOT NULL,
            order_item_id bigint unsigned NOT NULL DEFAULT 0,
            source_type varchar(30) NOT NULL,
            source_id bigint unsigned NOT NULL,
            buyer_id bigint unsigned NOT NULL,
            recipient_role varchar(60) NOT NULL DEFAULT '',
            recipient_id bigint unsigned NULL,
            rule_type varchar(20) NOT NULL,
            rule_value decimal(18,4) NOT NULL DEFAULT 0,
            base_amount decimal(18,2) NOT NULL DEFAULT 0,
            payout_amount decimal(18,2) NOT NULL DEFAULT 0,
            status varchar(20) NOT NULL,
            reason varchar(255) NOT NULL DEFAULT '',
            created_at datetime NOT NULL,
            processed_at datetime NULL,
            reversed_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY transaction_key (transaction_key),
            KEY order_id (order_id),
            KEY recipient_id (recipient_id),
            KEY status (status),
            KEY created_at (created_at)
        ) " . $wpdb->get_charset_collate() . ';';
        dbDelta( $sql );
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    public function process_order( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! method_exists( $order, 'get_items' ) ) {
            return;
        }
        $buyer_id = (int) $order->get_customer_id();
        $paid = $skipped = 0;
        foreach ( $order->get_items() as $item_index => $item ) {
            $source_id = (int) get_post_meta( $item->get_product_id(), self::META_LINKED, true );
            $source_type = $source_id ? (string) get_post_type( $source_id ) : '';
            if ( ! in_array( $source_type, [ 'competition', 'course' ], true ) ) {
                continue;
            }
            $rules = (array) get_post_meta( $source_id, '_' . $source_type . '_payouts', true );
            $base = max( 0.0, (float) $item->get_total() );
            $item_id = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : (int) $item_index;
            if ( ! self::rules_fit_base( $rules, $base ) ) {
                $this->record_configuration_failure( $order_id, $item_id, $source_type, $source_id, $buyer_id, $base );
                ++$skipped;
                continue;
            }
            foreach ( $rules as $index => $rule ) {
                $role = (string) ( $rule['role'] ?? '' );
                $recipient = $this->resolve_recipient( $rule, $buyer_id );
                $amount = $this->calculate_amount( $rule, $base );
                $tx = hash( 'sha256', implode( '|', [ $order_id, $item_id, $source_id, $index, $role, $recipient ] ) );
                $reason = $recipient > 0 ? '' : 'گیرنده فعال مطابق اطلاعات ورزشکار یافت نشد.';
                $status = ( $recipient > 0 && $amount > 0 ) ? 'processing' : 'skipped';
                if ( ! $this->claim( $tx, $order_id, $item_id, $source_type, $source_id, $buyer_id, $role, $recipient, $rule, $base, $amount, $status, $reason ) ) {
                    continue;
                }
                if ( $status === 'skipped' ) {
                    ++$skipped;
                    continue;
                }
                try {
                    Wallet::add_balance( $recipient, $amount );
                    $this->add_wallet_log( $recipient, $amount, $order_id, $source_type, $source_id, $role, $tx );
                    $this->finish( $tx, 'paid' );
                    ++$paid;
                } catch ( \Throwable $error ) {
                    $this->finish( $tx, 'failed', mb_substr( $error->getMessage(), 0, 250 ) );
                    ++$skipped;
                }
            }
        }
        if ( ( $paid || $skipped ) && method_exists( $order, 'add_order_note' ) ) {
            $order->add_order_note( sprintf( 'تسهیم خودکار: %d پرداخت موفق و %d مورد رد/ناموفق. جزئیات در «کاربران ← گزارش تسهیم درآمد» ثبت شد.', $paid, $skipped ) );
        }
    }

    public function reverse_order( int $order_id ): void {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$this->table()} WHERE order_id=%d AND status='paid'", $order_id ) );
        foreach ( $rows as $row ) {
            $amount = (float) $row->payout_amount;
            $recipient = (int) $row->recipient_id;
            if ( $recipient <= 0 || $amount <= 0 ) {
                continue;
            }
            $available = Wallet::get_balance( $recipient );
            $deducted = min( $available, $amount );
            if ( $deducted > 0 ) {
                Wallet::deduct_balance( $recipient, $deducted );
                $this->add_wallet_log( $recipient, -$deducted, $order_id, (string) $row->source_type, (int) $row->source_id, (string) $row->recipient_role, (string) $row->transaction_key );
            }
            $complete = $deducted + 0.01 >= $amount;
            $wpdb->update( $this->table(), [
                'status' => $complete ? 'reversed' : 'reversal_failed',
                'reason' => $complete ? '' : sprintf( 'موجودی گیرنده کافی نبود؛ مبلغ باقیمانده قابل وصول: %.2f', $amount - $deducted ),
                'reversed_at' => current_time( 'mysql' ),
            ], [ 'id' => (int) $row->id ], [ '%s', '%s', '%s' ], [ '%d' ] );
        }
    }

    public static function rules_fit_base( array $rules, float $base ): bool {
        $percent = $fixed = 0.0;
        foreach ( $rules as $rule ) {
            $value = max( 0.0, (float) ( $rule['value'] ?? 0 ) );
            if ( ( $rule['type'] ?? 'percent' ) === 'percent' ) {
                $percent += $value;
            } else {
                $fixed += $value;
            }
        }
        return $percent <= 100.0001 && ( ( $base * $percent / 100 ) + $fixed ) <= $base + 0.01;
    }

    private function calculate_amount( array $rule, float $base ): float {
        $value = max( 0.0, (float) ( $rule['value'] ?? 0 ) );
        return round( ( $rule['type'] ?? 'percent' ) === 'percent' ? $base * $value / 100 : $value, 2 );
    }

    private function resolve_recipient( array $rule, int $buyer_id ): int {
        if ( ( $rule['recipient_type'] ?? 'user' ) !== 'predefined' ) {
            return (int) ( $rule['user_id'] ?? 0 );
        }
        $definition = \IMAOCustom\Plugin::get_payout_roles()[ (string) ( $rule['role'] ?? '' ) ] ?? [];
        $resolver = $definition['resolver'] ?? '';
        if ( $resolver === 'user_meta' ) {
            return (int) get_user_meta( $buyer_id, $definition['meta_key'] ?? '', true );
        }
        if ( $resolver === 'user_meta_post_author' ) {
            $post_id = (int) get_user_meta( $buyer_id, $definition['meta_key'] ?? '', true );
            return $post_id ? (int) get_post_field( 'post_author', $post_id ) : 0;
        }
        if ( $resolver !== 'representative' ) {
            return 0;
        }
        $manager = new RepresentativeManager();
        $manager->install();
        global $wpdb;
        $province = (string) get_user_meta( $buyer_id, 'residence_province', true );
        $city = (string) get_user_meta( $buyer_id, 'residence_city', true );
        $gender = UserMeta::gender_slug( $buyer_id );
        if ( ( $definition['scope'] ?? '' ) === 'city' ) {
            if ( $province === '' || $city === '' ) {
                return 0;
            }
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$manager->get_city_table()} WHERE province_code=%s AND city_name=%s AND status='active' AND (gender=%s OR gender='' OR gender IS NULL) ORDER BY (gender=%s) DESC, id DESC LIMIT 1", $province, $city, $gender, $gender ) );
        }
        if ( $province === '' ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$manager->get_province_table()} WHERE province_code=%s AND status='active' AND (gender=%s OR gender='' OR gender IS NULL) ORDER BY (gender=%s) DESC, id DESC LIMIT 1", $province, $gender, $gender ) );
    }

    private function claim( string $tx, int $order_id, int $item_id, string $source_type, int $source_id, int $buyer_id, string $role, int $recipient, array $rule, float $base, float $amount, string $status, string $reason ): bool {
        global $wpdb;
        return (bool) $wpdb->insert( $this->table(), [
            'transaction_key' => $tx, 'order_id' => $order_id, 'order_item_id' => $item_id,
            'source_type' => $source_type, 'source_id' => $source_id, 'buyer_id' => $buyer_id,
            'recipient_role' => $role, 'recipient_id' => $recipient ?: null,
            'rule_type' => (string) ( $rule['type'] ?? 'percent' ), 'rule_value' => (float) ( $rule['value'] ?? 0 ),
            'base_amount' => $base, 'payout_amount' => $amount, 'status' => $status,
            'reason' => $reason, 'created_at' => current_time( 'mysql' ),
            'processed_at' => $status === 'skipped' ? current_time( 'mysql' ) : null,
        ] );
    }

    private function finish( string $tx, string $status, string $reason = '' ): void {
        global $wpdb;
        $wpdb->update( $this->table(), [ 'status' => $status, 'reason' => $reason, 'processed_at' => current_time( 'mysql' ) ], [ 'transaction_key' => $tx ] );
    }

    private function record_configuration_failure( int $order_id, int $item_id, string $source_type, int $source_id, int $buyer_id, float $base ): void {
        $tx = hash( 'sha256', implode( '|', [ $order_id, $item_id, $source_id, 'invalid-config' ] ) );
        $this->claim( $tx, $order_id, $item_id, $source_type, $source_id, $buyer_id, 'configuration', 0, [ 'type' => 'percent', 'value' => 0 ], $base, 0, 'skipped', 'مجموع تسهیم از مبلغ قلم سفارش بیشتر است.' );
    }

    private function add_wallet_log( int $user_id, float $amount, int $order_id, string $source_type, int $source_id, string $role, string $tx ): void {
        $log = (array) get_user_meta( $user_id, 'crm_wallet_log', true );
        $log[] = [
            'date' => current_time( 'mysql' ), 'amount' => $amount,
            'note' => sprintf( '%s تسهیم %s #%d، سفارش #%d', $amount < 0 ? 'برگشت' : 'درآمد', $source_type === 'competition' ? 'مسابقه' : 'دوره', $source_id, $order_id ),
            'transaction_id' => $tx, 'role' => $role,
        ];
        update_user_meta( $user_id, 'crm_wallet_log', $log );
    }

    private function table(): string {
        if ( $this->table === '' ) {
            global $wpdb;
            $this->table = $wpdb->prefix . 'imao_payout_audit';
        }
        return $this->table;
    }

    public function register_admin_page(): void {
        add_users_page( 'گزارش تسهیم درآمد', 'گزارش تسهیم درآمد', 'manage_options', 'crm-payout-audit', [ $this, 'render_admin_page' ] );
    }

    public function render_admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Access denied' );
        }
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY id DESC" );
        $roles = \IMAOCustom\Plugin::get_payout_roles();
        echo '<div class="wrap"><h1>گزارش تسهیم درآمد</h1><p>هر ردیف نتیجه اجرای یک قانون تسهیم است. شناسه تراکنش از دوباره‌پرداختی جلوگیری می‌کند.</p>';
        echo '<table class="widefat striped" id="crm-payout-audit-table"><thead><tr><th>شناسه</th><th>سفارش</th><th>مسابقه / دوره</th><th>خریدار</th><th>نقش</th><th>گیرنده</th><th>مبنای محاسبه</th><th>قانون</th><th>مبلغ تسهیم</th><th>وضعیت</th><th>علت</th><th>تاریخ</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $source = get_the_title( (int) $row->source_id ) ?: '#' . (int) $row->source_id;
            $buyer = get_userdata( (int) $row->buyer_id );
            $recipient = $row->recipient_id ? get_userdata( (int) $row->recipient_id ) : null;
            $role = $roles[ $row->recipient_role ]['label'] ?? $row->recipient_role;
            $rule = $row->rule_type === 'percent' ? $row->rule_value . '٪' : number_format_i18n( (float) $row->rule_value ) . ' تومان';
            printf( '<tr><td>%d</td><td><a href="%s">#%d</a></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                (int) $row->id, esc_url( admin_url( 'post.php?post=' . (int) $row->order_id . '&action=edit' ) ), (int) $row->order_id,
                esc_html( $source ), esc_html( $buyer ? $buyer->display_name : '#' . $row->buyer_id ), esc_html( $role ),
                esc_html( $recipient ? $recipient->display_name : '—' ), esc_html( number_format_i18n( (float) $row->base_amount ) ), esc_html( $rule ),
                esc_html( number_format_i18n( (float) $row->payout_amount ) ), esc_html( $this->status_label( $row->status ) ), esc_html( $row->reason ?: '—' ), esc_html( $row->created_at ) );
        }
        echo '</tbody></table></div>';
    }

    private function status_label( string $status ): string {
        return [ 'processing' => 'در حال پردازش', 'paid' => 'پرداخت‌شده', 'skipped' => 'ردشده', 'failed' => 'ناموفق', 'reversed' => 'برگشت‌خورده', 'reversal_failed' => 'برگشت ناقص' ][ $status ] ?? $status;
    }
}
