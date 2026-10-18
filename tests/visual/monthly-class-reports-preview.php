<?php
/** Disposable standalone preview: no WordPress database or payment data. */
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( isset( $_GET['ajax'] ) ) {
    header( 'Content-Type: application/json' );
    if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ( $_POST['action'] ?? '' ) !== 'imao_monthly_class_reports'
        || ( $_POST['nonce'] ?? '' ) !== 'fixture-nonce' ) {
        http_response_code( 400 );
        echo json_encode( [ 'error' => 'Missing POST action/nonce' ] );
        exit;
    }
    echo json_encode( [ 'draw' => (int) ( $_POST['draw'] ?? 0 ), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [] ] );
    exit;
}

function current_user_can( $cap ) { return true; }
function admin_url( $path ) { return '/tests/visual/monthly-class-reports-preview.php?ajax=1'; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function wp_create_nonce( $action ) { return 'fixture-nonce'; }
$wpdb = new class {
    public string $prefix = 'fixture_';
    public string $posts = 'fixture_posts';
    public function prepare( $sql, ...$args ) { return $sql; }
    public function esc_like( $value ) { return $value; }
    public function get_var( $sql ) { return 'fixture_imao_class_tuition'; }
    public function get_results( $sql ) { return []; }
};
$report = new IMAOCustom\Services\Admin\MonthlyClassReports();
$script = new ReflectionMethod( $report, 'script' );
$script->setAccessible( true );
?>
<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>Monthly class report regression preview</title>
<link rel="icon" href="data:,">
<link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css">
<script src="https://irimao.com/wp-includes/js/jquery/jquery.min.js"></script>
<script src="/assets/js/jquery.dataTables.min.js"></script></head><body>
<?php $report->render_page(); ?>
<script>
jQuery.ajaxPrefilter(function (options) {
    document.body.dataset.reportRequest = JSON.stringify({method: options.type, data: options.data});
});
<?php echo $script->invoke( $report ); ?>
</script></body></html>
