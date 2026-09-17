<?php

namespace IMAOCustom\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\Date;
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

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_competition', [ $this, 'save_weigh_ins' ], 20, 2 );
        add_action( 'admin_post_imao_competition_card', [ $this, 'render_card' ] );
        add_action( 'template_redirect', [ $this, 'render_frontend_card' ], 0 );
        add_action( 'admin_post_imao_verify_competition_card', [ $this, 'verify_card' ] );
        add_action( 'admin_post_nopriv_imao_verify_competition_card', [ $this, 'verify_card' ] );
    }

    public function add_meta_box(): void {
        add_meta_box( 'imao_competition_cards', 'وزن‌کشی و صدور کارت مسابقه', [ $this, 'render_meta_box' ], 'competition', 'normal', 'default' );
    }

    public function render_meta_box( WP_Post $post ): void {
        wp_nonce_field( 'imao_save_competition_weigh_ins_' . $post->ID, 'imao_competition_weigh_ins_nonce' );
        $entries = $this->paid_entries( $post->ID );
        if ( ! $entries ) {
            echo '<p>هنوز پرداخت موفقی برای این مسابقه ثبت نشده است.</p>';
            return;
        }
        echo '<p>کارت پس از پرداخت موفق، بلافاصله در پنل ورزشکار قابل دانلود و چاپ است. تأیید وزن‌کشی فقط برای ورود به جدول حذفی مسابقه لازم است.</p>';
        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>ردیف</th><th>ورزشکار</th><th>کد ملی</th><th>سفارش</th><th>دسته ثبت‌نامی</th><th>پایان اعتبار بیمه ورزشی</th><th>پایان اعتبار کارت عضویت فدراسیون</th><th>وزن واقعی (KG)</th><th>تأیید وزن‌کشی</th><th>کارت</th></tr></thead><tbody>';
        $row_number = 1;
        foreach ( $entries as $entry ) {
            $item = $entry['item'];
            $order = $entry['order'];
            $item_id = (int) $item->get_id();
            $user = get_userdata( (int) $order->get_customer_id() );
            $ready = self::item_is_ready( $order, $item );
            echo '<tr><td>' . (int) $row_number++ . '</td><td>' . esc_html( $user ? $user->display_name : '#' . $order->get_customer_id() ) . '</td>';
            echo '<td>' . esc_html( (string) get_user_meta( (int) $order->get_customer_id(), 'national_id', true ) ?: '—' ) . '</td>';
            echo '<td>#' . (int) $order->get_id() . '</td><td>' . esc_html( (string) $item->get_meta( 'دسته وزنی', true ) ) . '</td>';
            echo '<td><input type="text" class="crm-date" data-jdp data-jdp-only-date inputmode="numeric" placeholder="۱۴۰۵/۰۵/۱۷" name="imao_weigh_ins[' . $item_id . '][sports_insurance_expiry]" value="' . esc_attr( (string) $item->get_meta( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, true ) ) . '" style="width:130px"></td>';
            echo '<td><input type="text" class="crm-date" data-jdp data-jdp-only-date inputmode="numeric" placeholder="۱۴۰۵/۰۵/۱۷" name="imao_weigh_ins[' . $item_id . '][federation_membership_expiry]" value="' . esc_attr( (string) $item->get_meta( self::ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY, true ) ) . '" style="width:130px"></td>';
            echo '<td><input type="number" min="1" max="300" step="0.01" name="imao_weigh_ins[' . $item_id . '][weight]" value="' . esc_attr( (string) $item->get_meta( self::META_WEIGHT, true ) ) . '" style="width:100px"></td>';
            echo '<td><label><input type="checkbox" name="imao_weigh_ins[' . $item_id . '][confirmed]" value="1" ' . checked( 'yes', $item->get_meta( self::META_CONFIRMED, true ), false ) . '> تأیید شد</label></td>';
            echo '<td>' . ( $ready ? '<a class="button" target="_blank" href="' . esc_url( self::card_url( (int) $order->get_id(), $item_id ) ) . '">مشاهده کارت</a>' : 'در انتظار' ) . '</td></tr>';
        }
        echo '</tbody></table></div><p><strong>توجه:</strong> برای اعمال تغییرات، دکمه «به‌روزرسانی» مسابقه را بزنید.</p>';
    }

    public function save_weigh_ins( int $competition_id, WP_Post $post ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $competition_id ) ) return;
        $nonce = $_POST['imao_competition_weigh_ins_nonce'] ?? '';
        if ( ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'imao_save_competition_weigh_ins_' . $competition_id ) ) return;
        $submitted = isset( $_POST['imao_weigh_ins'] ) && is_array( $_POST['imao_weigh_ins'] ) ? wp_unslash( $_POST['imao_weigh_ins'] ) : [];
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
            $confirmed = ! empty( $row['confirmed'] ) && $weight > 0;
            $item->update_meta_data( self::META_WEIGHT, $weight > 0 ? $weight : '' );
            $item->update_meta_data( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, $sports_insurance_expiry );
            $item->update_meta_data( self::ITEM_META_FEDERATION_MEMBERSHIP_EXPIRY, $federation_membership_expiry );
            $item->update_meta_data( self::META_CONFIRMED, $confirmed ? 'yes' : 'no' );
            if ( $confirmed ) {
                $item->update_meta_data( self::META_WEIGHED_AT, current_time( 'mysql' ) );
                $item->update_meta_data( self::META_WEIGHED_BY, get_current_user_id() );
            } else {
                $item->delete_meta_data( self::META_WEIGHED_AT );
                $item->delete_meta_data( self::META_WEIGHED_BY );
            }
            $item->save();
        }
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

    public static function card_url( int $order_id, int $item_id ): string {
        return add_query_arg(
            [
                'imao_competition_card' => 1,
                'order_id'              => $order_id,
                'item_id'               => $item_id,
                'token'                 => self::card_access_token( $order_id, $item_id ),
            ],
            home_url( '/' )
        );
    }

    public function render_frontend_card(): void {
        if ( (int) ( $_GET['imao_competition_card'] ?? 0 ) !== 1 ) return;
        $this->render_card();
    }

    public function render_card(): void {
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
        echo $this->card_document( $data );
        exit;
    }

    public function verify_card(): void {
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
            'national_id' => $national_id,
            'competition_dates' => self::format_competition_date_range( $start_date, $end_date ),
            'insurance_date' => (string) $item->get_meta( self::ITEM_META_SPORTS_INSURANCE_EXPIRY, true ) ?: (string) $item->get_meta( self::META_INSURANCE_DATE, true ),
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
        $renderer = new ImageRenderer( new RendererStyle( 420, 16 ), new SvgImageBackEnd() );
        $svg = ( new Writer( $renderer ) )->writeString( $content );
        return 'data:image/svg+xml;base64,' . base64_encode( $svg );
    }

    /** @param array<string,string> $data */
    private function card_document( array $data ): string {
        $e = static fn( string $value ): string => esc_html( $value ?: '—' );
        ob_start(); ?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>کارت مسابقه</title>
<style>
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#202124;font-family:Tahoma,Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:2;padding:10px;text-align:center;background:#fff}.toolbar button{padding:8px 20px;border:0;border-radius:5px;background:#17134b;color:#fff;font:700 14px Tahoma;cursor:pointer}.page{display:flex;justify-content:center;padding:18px}.card{position:relative;width:min(92vw,574px);aspect-ratio:565/776;background:center/100% 100% no-repeat;overflow:hidden;box-shadow:0 8px 30px #0008}.competition-date{position:absolute;z-index:2;top:35.5%;left:2%;width:70%;height:7.5%;display:flex;align-items:center;justify-content:center;background:#fff;color:#c32921;font-size:clamp(12px,2.7vw,20px);font-weight:700;white-space:nowrap}.field{position:absolute;z-index:3;right:27%;width:26%;height:4.3%;display:flex;align-items:center;color:#050505;padding:0;font-weight:700;font-size:clamp(11px,2.1vw,18px);line-height:1}.field b{display:none}.field span{width:100%;text-align:right;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.name{top:47.5%}.national-id{top:53.0%}.province{top:58.5%}.weight{top:64.0%}.age{top:69.5%}.insurance{top:75.0%}.photo{position:absolute;z-index:3;left:3.5%;top:45.2%;width:28%;height:24%;background:#fff;border:3px solid #070707;object-fit:cover}.qr{position:absolute;z-index:3;left:2.3%;top:86.1%;width:17%;height:10.5%;display:block;background:#fff;object-fit:contain}.print-note{display:none}
@page{size:A4 portrait;margin:0}@media print{html,body{width:100%;height:100%;background:#fff}.toolbar{display:none}.page{width:100%;height:100%;padding:0;align-items:center}.card{height:100vh;width:auto;max-width:100vw;box-shadow:none;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div><main class="page"><section class="card" style="background-image:url('<?php echo esc_url( $data['background'] ); ?>')">
<div class="competition-date"><?php echo $e( $data['competition_dates'] ); ?></div>
<div class="field name"><b>نام و نام خانوادگی</b><span><?php echo $e( $data['name'] ); ?></span></div>
<div class="field national-id"><b>کد ملی</b><span><?php echo $e( $data['national_id'] ); ?></span></div>
<div class="field province"><b>استان</b><span><?php echo $e( $data['province'] ); ?></span></div>
<div class="field weight"><b>وزن</b><span><?php echo $e( $data['weight'] ); ?></span></div>
<div class="field age"><b>رده سنی</b><span><?php echo $e( $data['age'] ); ?></span></div>
<div class="field insurance"><b>تاریخ اعتبار بیمه</b><span><?php echo $e( $data['insurance_date'] ); ?></span></div>
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
        foreach ( $orders as $order ) {
            foreach ( $order->get_items() as $item ) {
                if ( $item instanceof WC_Order_Item_Product && (int) $item->get_product_id() === $product_id ) $entries[] = [ 'order' => $order, 'item' => $item ];
            }
        }
        return $entries;
    }
}
