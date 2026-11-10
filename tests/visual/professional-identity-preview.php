<?php
/** Real table controls with synthetic users; never connects to WordPress. */
require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
function current_user_can( $cap ) { return true; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function admin_url( $path ) { return '/tests/visual/professional-identity-preview.php'; }
function wp_create_nonce( $action ) { return 'fixture'; }
function wp_nonce_field( $action, $name, $referer = true, $echo = true ) {
    $html = '<input type="hidden" name="' . esc_attr( $name ) . '" value="fixture">';
    if ( $echo ) echo $html;
    return $html;
}
function wp_roles() { return (object) [ 'roles' => [ 'subscriber' => [ 'name' => 'کاربر' ], 'coach' => [ 'name' => 'مربی' ] ] ]; }
function get_user_meta( $id, $key, $single = true ) {
    return [ 'first_name_fa' => 'ورزشکار', 'last_name_fa' => (string) $id, 'residence_province' => 'THR', 'residence_city' => 'تهران', 'identity_verified_professional' => 'pending' ][ $key ] ?? '';
}
function wp_timezone() { return new DateTimeZone( 'Asia/Tehran' ); }
if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
    $start = max( 0, (int) ( $_POST['start'] ?? 0 ) );
    $length = min( 100, max( 1, (int) ( $_POST['length'] ?? 15 ) ) );
    $users = [];
    for ( $id = $start + 1; $id <= min( 47, $start + $length ); ++$id ) {
        $users[] = (object) [ 'ID' => $id, 'display_name' => 'ورزشکار ' . $id, 'user_registered' => '2026-11-10 10:00:00', 'roles' => [ 'subscriber', 'coach' ] ];
    }
    header( 'Content-Type: application/json' );
    echo json_encode( [ 'draw' => (int) ( $_POST['draw'] ?? 1 ), 'recordsTotal' => 47, 'recordsFiltered' => 47, 'data' => ( new \IMAOCustom\Services\Admin\UserManagement() )->professional_rows( $users ) ] );
    exit;
}
?><!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>بررسی هویت حرفه‌ای</title>
<link rel="stylesheet" href="/assets/css/jquery.dataTables.min.css"><link rel="stylesheet" href="/assets/css/select2.min.css"><link rel="stylesheet" href="/assets/css/crm-admin.css"><link rel="stylesheet" href="/assets/css/admin-data-grid.css"><link rel="stylesheet" href="/assets/css/jalalidatepicker.min.css">
<style>body{font-family:Tahoma;padding:24px}.imao-grid-toolbar{padding:16px;gap:12px;display:flex;flex-wrap:wrap}button,select,input{padding:7px}td{font-size:13px}</style>
<?php ( new \IMAOCustom\Services\Admin\ProfessionalIdentityTable() )->render_page(); ?>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script><script src="/assets/js/jquery.dataTables.min.js"></script><script src="/assets/js/select2.min.js"></script><script src="/assets/js/jalalidatepicker.min.js"></script><script>var CRM_ADMIN={ajax:location.pathname,nonce:'fixture'};jalaliDatepicker.startWatch();</script><script src="/assets/js/crm-admin.js"></script></html>
