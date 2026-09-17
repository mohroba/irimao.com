<?php

namespace IMAOCustom\Services;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use IMAOCustom\Helpers\CityMap;
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

    public function register(): void {
        add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
        add_action( 'save_post_competition', [ $this, 'save_weigh_ins' ], 20, 2 );
        add_action( 'admin_post_imao_competition_card', [ $this, 'render_card' ] );
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
        $insurance_expiry = (string) get_post_meta( $post->ID, 'sports_insurance_expiry_date', true );
        $membership_expiry = (string) get_post_meta( $post->ID, 'federation_membership_expiry_date', true );
        echo '<table class="widefat striped" style="max-width:680px;margin:12px 0"><tbody>';
        echo '<tr><th>پایان اعتبار بیمه ورزشی</th><td>' . esc_html( $insurance_expiry ?: 'ثبت نشده' ) . '</td></tr>';
        echo '<tr><th>پایان اعتبار کارت عضویت فدراسیون</th><td>' . esc_html( $membership_expiry ?: 'ثبت نشده' ) . '</td></tr>';
        echo '</tbody></table>';
        echo '<div style="overflow:auto"><table class="widefat striped"><thead><tr><th>ورزشکار</th><th>سفارش</th><th>دسته ثبت‌نامی</th><th>وزن واقعی (KG)</th><th>تاریخ بیمه ورزشی</th><th>تأیید وزن‌کشی</th><th>کارت</th></tr></thead><tbody>';
        foreach ( $entries as $entry ) {
            $item = $entry['item'];
            $order = $entry['order'];
            $item_id = (int) $item->get_id();
            $user = get_userdata( (int) $order->get_customer_id() );
            $ready = self::item_is_ready( $order, $item );
            echo '<tr><td>' . esc_html( $user ? $user->display_name : '#' . $order->get_customer_id() ) . '</td>';
            echo '<td>#' . (int) $order->get_id() . '</td><td>' . esc_html( (string) $item->get_meta( 'دسته وزنی', true ) ) . '</td>';
            echo '<td><input type="number" min="1" max="300" step="0.01" name="imao_weigh_ins[' . $item_id . '][weight]" value="' . esc_attr( (string) $item->get_meta( self::META_WEIGHT, true ) ) . '" style="width:100px"></td>';
            echo '<td><input type="text" class="crm-date" data-jdp data-jdp-only-date inputmode="numeric" placeholder="۱۴۰۵/۰۵/۱۷" name="imao_weigh_ins[' . $item_id . '][insurance_date]" value="' . esc_attr( (string) $item->get_meta( self::META_INSURANCE_DATE, true ) ) . '" style="width:130px"></td>';
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
            $insurance_date = sanitize_text_field( $row['insurance_date'] ?? '' );
            $confirmed = ! empty( $row['confirmed'] ) && $weight > 0;
            $item->update_meta_data( self::META_WEIGHT, $weight > 0 ? $weight : '' );
            $item->update_meta_data( self::META_INSURANCE_DATE, $insurance_date );
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
        $url = add_query_arg( [ 'action' => 'imao_competition_card', 'order_id' => $order_id, 'item_id' => $item_id ], admin_url( 'admin-post.php' ) );
        return wp_nonce_url( $url, 'imao_competition_card_' . $order_id . '_' . $item_id );
    }

    public function render_card(): void {
        if ( ! is_user_logged_in() ) auth_redirect();
        $order_id = (int) ( $_GET['order_id'] ?? 0 );
        $item_id = (int) ( $_GET['item_id'] ?? 0 );
        check_admin_referer( 'imao_competition_card_' . $order_id . '_' . $item_id );
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
        $city = (string) get_user_meta( $user_id, 'residence_city', true );
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
            'insurance_date' => (string) $item->get_meta( self::META_INSURANCE_DATE, true ),
            'weight' => $weight,
            'age' => (string) $item->get_meta( 'رده سنی', true ),
            'city' => trim( $province . ( $province && $city ? ' - ' : '' ) . $city ),
            'photo' => (string) get_user_meta( $user_id, 'personal_photo', true ),
            'background' => esc_url_raw( $background ),
            'verification_url' => $verification_url,
            'qr_data_uri' => self::qr_data_uri( $verification_url ),
        ];
    }

    private static function verification_token( int $order_id, int $item_id, int $user_id ): string {
        return hash_hmac( 'sha256', $order_id . '|' . $item_id . '|' . $user_id, wp_salt( 'auth' ) );
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
*{box-sizing:border-box}html,body{margin:0;min-height:100%;background:#202124;font-family:Tahoma,Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:2;padding:10px;text-align:center;background:#fff}.toolbar button{padding:9px 24px;border:0;border-radius:5px;background:#17134b;color:#fff;font:700 15px Tahoma;cursor:pointer}.page{display:flex;justify-content:center;padding:18px}.card{position:relative;width:min(92vw,574px);aspect-ratio:881/1280;background:center/100% 100% no-repeat;overflow:hidden;box-shadow:0 8px 30px #0008}.field{position:absolute;right:8.9%;width:51.8%;height:4.3%;display:flex;align-items:center;border-radius:999px;background:#fff;color:#050505;padding:0 3%;font-weight:700;font-size:clamp(12px,2.4vw,21px);line-height:1}.field b{font-size:.55em;margin-left:1.4%;white-space:nowrap}.field span{flex:1;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.name{top:32.5%}.insurance{top:37.35%}.weight{top:42.25%}.age{top:47.15%}.city{top:51.7%}.photo{position:absolute;left:9.1%;top:33.35%;width:28.5%;height:23.4%;background:#fff;border:3px solid #070707;object-fit:cover}.qr{position:absolute;left:9.1%;top:57.45%;width:26.3%;height:18.2%;display:block;background:#fff;object-fit:contain}.print-note{display:none}
@page{size:A4 portrait;margin:0}@media print{html,body{width:100%;height:100%;background:#fff}.toolbar{display:none}.page{width:100%;height:100%;padding:0;align-items:center}.card{height:100vh;width:auto;max-width:100vw;box-shadow:none;print-color-adjust:exact;-webkit-print-color-adjust:exact}}
</style></head><body><div class="toolbar"><button type="button" onclick="window.print()">چاپ / ذخیره PDF</button></div><main class="page"><section class="card" style="background-image:url('<?php echo esc_url( $data['background'] ); ?>')">
<div class="field name"><b>اسم و فامیل</b><span><?php echo $e( $data['name'] ); ?></span></div>
<div class="field insurance"><b>تاریخ بیمه ورزشی</b><span><?php echo $e( $data['insurance_date'] ); ?></span></div>
<div class="field weight"><b>وزن</b><span><?php echo $e( $data['weight'] ); ?></span></div>
<div class="field age"><b>رده سنی</b><span><?php echo $e( $data['age'] ); ?></span></div>
<div class="field city"><b>شهر</b><span><?php echo $e( $data['city'] ); ?></span></div>
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
