<?php
// Disposable WooCommerce/WordPress fixtures; no real users or orders are changed.
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

class WP_Post { public $ID = 1; public $post_status = 'publish'; }
class WC_Order_Item_Product {
    public $id;
    public $meta;
    public function __construct( int $id ) { $this->id = $id; $this->meta = [ '_imao_dormitory_requested' => $id <= 25 ? 'yes' : 'no', 'دسته وزنی' => '۶۵ کیلوگرم', '_imao_weigh_in_confirmed' => 'yes' ]; }
    public function get_id() { return $this->id; }
    public function get_product_id() { return 99; }
    public function get_meta( $key, $single = true ) { return $this->meta[$key] ?? ''; }
    public function update_meta_data( $key, $value ) { $this->meta[$key] = $value; }
    public function delete_meta_data( $key ) { unset( $this->meta[$key] ); }
    public function save() {}
}
class FixtureOrder {
    public $id;
    public $item;
    public function __construct( int $id ) { $this->id = $id; $this->item = new WC_Order_Item_Product( $id ); }
    public function get_id() { return $this->id; }
    public function get_customer_id() { return $this->id; }
    public function get_items() { return [ $this->item ]; }
    public function has_status( $statuses ) { return true; }
}
$orders = array_map( static fn( $id ) => new FixtureOrder( $id ), range( 1, 45 ) );
$wpdb = new class {
    public $prefix = 'wp_';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_col( $sql ) { return range( 1, 45 ); }
};
function get_post_meta( $id, $key, $single = true ) { return $key === '_linked_product_id' ? 99 : ''; }
function wc_get_orders( $args ) { return $GLOBALS['orders']; }
function get_userdata( $id ) { return (object) [ 'display_name' => 'ورزشکار ' . $id ]; }
function get_user_meta( $id, $key, $single = true ) { return [ 'first_name_fa' => 'ورزشکار', 'last_name_fa' => (string) $id, 'national_id' => '001234' . str_pad( (string) $id, 4, '0', STR_PAD_LEFT ), 'billing_phone' => '09120000000' ][$key] ?? ''; }
function wp_nonce_field( ...$args ) {}
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=fixture'; }
function wp_unslash( $value ) { return $value; }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function get_the_terms( ...$args ) { return []; }
function wp_get_post_terms( ...$args ) { return []; }
function get_terms( ...$args ) { return []; }
function is_wp_error( $value ) { return false; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_attr( $value ); }
function admin_url( $path ) { return '/' . $path; }
function home_url( $path ) { return $path; }
function add_query_arg( $args, $url ) { return $url . '?' . http_build_query( $args ); }
function wp_salt( ...$args ) { return 'disposable-fixture-salt'; }
function checked( $a, $b, $echo = false ) { return $a === $b ? 'checked' : ''; }
function selected( $a, $b, $echo = false ) { return $a === $b ? 'selected' : ''; }
function current_user_can( ...$args ) { return true; }
function wp_verify_nonce( ...$args ) { return true; }
function absint( $value ) { return abs( (int) $value ); }
function current_time( $type ) { return '2026-10-10 12:00:00'; }
function get_current_user_id() { return 1; }
function get_post_type( $id ) { return 'competition'; }
function check_admin_referer( ...$args ) {}
function check_ajax_referer( ...$args ) {}
function nocache_headers() {}
function wp_send_json( $data ) { $GLOBALS['fixture_json'] = $data; throw new RuntimeException( '__fixture_json__' ); }
function wp_send_json_error( $data, $status = null ) { $GLOBALS['fixture_json_error'] = [ $data, $status ]; throw new RuntimeException( '__fixture_json__' ); }

$service = new IMAOCustom\Services\CompetitionCards();
if ( ( $argv[1] ?? '' ) === 'export' ) { $_GET['competition_id'] = 1; $service->export_dormitory(); }
function render_fixture( $service ): string {
    ob_start();
    $service->render_meta_box( new WP_Post() );
    return ob_get_clean();
}
if ( ( $argv[1] ?? $_GET['fixture_mode'] ?? '' ) === 'html' ) {
    $_GET['imao_weigh_page'] = 2;
    echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><body><form><div id="imao_competition_cards">' . render_fixture( $service ) . '</div><input class="imao-input-fa" aria-label="نام فارسی"><input class="imao-input-en" aria-label="نام انگلیسی"></form><script src="/assets/js/weigh-in-pagination.js"></script><script src="/assets/js/input-language.js"></script></body></html>';
    exit;
}
function verify_fixture( $condition, $message ) { if ( ! $condition ) throw new RuntimeException( $message ); }
$html = render_fixture( $service );
verify_fixture( strpos( $html, 'مدیریت وزن‌کشی و صدور کارت' ) !== false, 'Edit meta box must link to dedicated weigh-in page.' );
$_POST = [ 'competition_id' => 1, 'draw' => 1, 'start' => 20, 'length' => 20, 'search' => [ 'value' => '' ] ];
try { $service->ajax_weigh_ins_datatable(); } catch ( RuntimeException $e ) { if ( $e->getMessage() !== '__fixture_json__' ) throw $e; }
verify_fixture( count( $GLOBALS['fixture_json']['data'] ) === 20, 'Server-side page two must return only 20 entries.' );
verify_fixture( strpos( $GLOBALS['fixture_json']['data'][0]['actual_weight'], 'imao_weigh_ins[21][weight]' ) !== false, 'Page two starts at entry 21.' );
verify_fixture( strpos( implode( ' ', array_column( $GLOBALS['fixture_json']['data'], 'actual_weight' ) ), 'imao_weigh_ins[1][weight]' ) === false, 'Page one inputs must be absent.' );
verify_fixture( count( $service->dormitory_rows( 1 ) ) === 25, 'Dormitory export must span pages.' );
verify_fixture( $service->dormitory_rows( 1 )[0][2] === '0012340001', 'National ID must keep leading zeroes.' );
$_POST = [ 'competition_id' => 1, 'draw' => 2, 'start' => 0, 'length' => 20, 'search' => [ 'value' => 'ورزشکار ۴۵' ] ];
try { $service->ajax_weigh_ins_datatable(); } catch ( RuntimeException $e ) { if ( $e->getMessage() !== '__fixture_json__' ) throw $e; }
verify_fixture( count( $GLOBALS['fixture_json']['data'] ) === 1 && strpos( $GLOBALS['fixture_json']['data'][0]['actual_weight'], 'imao_weigh_ins[45][weight]' ) !== false, 'Server-side search must find entries beyond page one.' );
$_POST = [ 'imao_competition_weigh_ins_nonce' => 'fixture', 'imao_weigh_ins' => [ 21 => [ 'weight' => '70', 'confirmed' => 1 ] ] ];
$service->save_weigh_ins( 1, new WP_Post() );
verify_fixture( $orders[20]->item->get_meta( '_imao_dormitory_requested' ) === 'no', 'Unchecked dormitory must save on submitted row.' );
verify_fixture( $orders[0]->item->get_meta( '_imao_dormitory_requested' ) === 'yes', 'Off-page checkbox state must be preserved.' );
verify_fixture( $orders[20]->item->get_meta( '_imao_actual_weight' ) === 70.0, 'Weight must save on submitted row.' );
echo "Competition admin integration: 9 checks passed.\n";
