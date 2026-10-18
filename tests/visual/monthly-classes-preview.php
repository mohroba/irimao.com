<?php
/** Local visual fixture for the real class shortcode renderers; no WordPress data. */
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

class WP_Query {
    public array $posts;
    public int $max_num_pages = 1;
    public function __construct( $args ) {
        $this->posts = [ (object) [ 'ID' => 51, 'post_title' => 'کلاس کیک‌بوکسینگ بزرگسالان', 'post_status' => 'publish' ] ];
    }
}

function is_user_logged_in() { return true; }
function get_current_user_id() { return 1; }
function wp_get_current_user() { return get_userdata( 1 ); }
function get_userdata( $id ) { return $id === 1 ? (object) [ 'ID' => 1, 'display_name' => 'مربی نمونه', 'roles' => [ 'subscriber', 'coach' ] ] : false; }
function get_user_meta( $id, $key, $single = true ) { return [ 'coach_id' => 1, 'identity_verified_professional' => 'approved' ][ $key ] ?? ''; }
function get_post_meta( $id, $key, $single = true ) {
    $data = [
        51 => [ '_class_coach_id' => 1, '_class_club_id' => 61, '_class_fee' => 250000, '_class_approved' => 'yes' ],
        61 => [ 'club_province' => 'THR', 'club_city' => 'تهران' ],
    ];
    return $data[ $id ][ $key ] ?? '';
}
function get_posts( $args ) {
    if ( $args['post_type'] === 'club_application' ) {
        return ( $args['fields'] ?? '' ) === 'ids' ? [ 61 ] : [ (object) [ 'ID' => 61, 'post_title' => 'باشگاه نمونه' ] ];
    }
    return [ (object) [ 'ID' => 51, 'post_title' => 'کلاس کیک‌بوکسینگ بزرگسالان', 'post_status' => 'publish' ] ];
}
function get_users( $args ) { return [ get_userdata( 1 ) ]; }
function get_the_title( $id ) { return $id === 61 ? 'باشگاه نمونه' : 'کلاس کیک‌بوکسینگ بزرگسالان'; }
function admin_url( $path ) { return '#'; }
function wp_nonce_field( $action, $name ) { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="fixture">'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_url_raw( $value ) { return $value; }
function wp_kses_post( $value ) { return $value; }
function wc_price( $amount ) { return number_format( (float) $amount ) . ' تومان'; }
function sanitize_text_field( $value ) { return strip_tags( (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function wp_unique_id( $prefix = '' ) { static $index = 0; return $prefix . ++$index; }
function wp_unslash( $value ) { return stripslashes( $value ); }
function selected( $a, $b, $echo = true ) { $text = $a == $b ? 'selected' : ''; if ( $echo ) echo $text; return $text; }
function remove_query_arg( $keys ) { return '/my-account/monthly-class-list/'; }
function add_query_arg( $args, $value = null, $url = '' ) { return ( $url ?: '/my-account/monthly-class-list/' ) . '?' . http_build_query( is_array( $args ) ? $args : [ $args => $value ] ); }
function wp_parse_url( $url, $component ) { return parse_url( $url, $component ); }
function paginate_links( $args ) { return ''; }

$wpdb = new class {
    public string $prefix = 'fixture_';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function get_var( $sql ) { return ''; }
};

$classes = new IMAOCustom\Services\MonthlyClasses();
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Monthly classes visual check</title>
<link rel="icon" href="data:,"><link rel="stylesheet" href="/assets/css/courses.css"><link rel="stylesheet" href="/assets/css/monthly-classes.css"></head>
<body style="background:#f5f7fb;margin:0;padding:20px;max-width:1100px;margin-inline:auto">
<?php echo $classes->classes_shortcode(); ?>
<?php echo $classes->directory_shortcode(); ?>
<?php echo $classes->submission_shortcode(); ?>
</body></html>
