<?php

namespace IMAOCustom\Services\Admin;

use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\Date;
use Morilog\Jalali\CalendarUtils;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Bounded identity queries; exports use the same filters in batches. */
class ProfessionalIdentityTable {
    private const NONCE = 'imao_professional_table';
    private ?array $provinces = null;

    private function provinces(): array {
        return $this->provinces ??= CityMap::get_provinces();
    }

    public function register(): void {
        add_action( 'wp_ajax_imao_professional_rows', [ $this, 'ajax_rows' ] );
        add_action( 'admin_post_imao_professional_export', [ $this, 'export' ] );
    }

    private function authorize(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی مجاز نیست.', '', [ 'response' => 403 ] );
        }
        check_admin_referer( self::NONCE, 'nonce' );
    }

    private static function input( array $request, string $key ): string {
        return isset( $request[ $key ] ) && is_scalar( $request[ $key ] )
            ? sanitize_text_field( wp_unslash( (string) $request[ $key ] ) ) : '';
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی مجاز نیست.', '', [ 'response' => 403 ] );
        }
        echo '<div class="wrap"><h1>تأیید هویت حرفه‌ای</h1><form id="imao-prof-filters" class="imao-grid-toolbar">';
        echo '<label>وضعیت <select name="status"><option value="">همه</option><option value="approved">مورد تایید</option><option value="pending">در انتظار</option><option value="disapproved">مردود</option></select></label> ';
        echo '<label>استان <select name="province"><option value="">همه</option>';
        foreach ( $this->provinces() as $code => $label ) {
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label> <label>شهرستان <input name="city" placeholder="شهرستان"></label> ';
        echo '<label>نقش <select name="role"><option value="">همه</option>';
        foreach ( wp_roles()->roles as $role => $details ) {
            echo '<option value="' . esc_attr( $role ) . '">' . esc_html( $details['name'] ) . '</option>';
        }
        echo '</select></label> <label>تاریخ عضویت از <input name="date_from" data-jdp autocomplete="off"></label> <label>تا <input name="date_to" data-jdp autocomplete="off"></label> ';
        echo '<button class="button button-primary">اعمال فیلتر</button> <button type="reset" class="button">پاک کردن فیلترها</button></form>';
        echo '<p><button class="button imao-prof-export" data-format="xlsx">خروجی XLSX</button> <button class="button imao-prof-export" data-format="print">خروجی PDF / چاپ</button></p>';
        echo '<table id="crm-prof-table" class="wp-list-table widefat striped" data-prof-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-export-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '"><thead><tr>';
        foreach ( $this->headers() as $header ) {
            echo '<th>' . esc_html( $header ) . '</th>';
        }
        echo '<th>عملیات</th></tr></thead><tbody></tbody></table><p class="description">جستجو بر اساس نام، موبایل، کد ملی، ایمیل و شناسه؛ خروجی شامل همه نتایج منطبق است، نه فقط صفحه جاری.</p></div>';
    }

    private function headers(): array {
        return [ 'ID', 'نام', 'استان', 'شهرستان', 'تاریخ عضویت', 'عکس پرسنلی', 'تصویر شناسنامه', 'تصویر کارت ملی', 'وضعیت', 'نقش' ];
    }

    public static function parse_date( string $value ): string {
        $value = CalendarUtils::convertNumbers( trim( $value ), true );
        if ( ! preg_match( '~^(\d{4})[/-](\d{1,2})[/-](\d{1,2})$~D', $value, $parts ) ) {
            throw new \InvalidArgumentException( 'قالب تاریخ نامعتبر است.' );
        }
        [ $year, $month, $day ] = array_map( 'intval', array_slice( $parts, 1 ) );
        if ( $year < 1700 ) {
            if ( $year < 1200 || ! CalendarUtils::checkDate( $year, $month, $day, true ) ) {
                throw new \InvalidArgumentException( 'تاریخ شمسی نامعتبر است.' );
            }
            [ $year, $month, $day ] = CalendarUtils::toGregorian( $year, $month, $day );
        }
        if ( ! checkdate( $month, $day, $year ) ) {
            throw new \InvalidArgumentException( 'تاریخ نامعتبر است.' );
        }
        return sprintf( '%04d-%02d-%02d', $year, $month, $day );
    }

    private function meta_condition( string $key, string $value, bool $partial = false ): string {
        global $wpdb;
        $operator = $partial ? 'LIKE' : '=';
        $value = $partial ? '%' . $wpdb->esc_like( $value ) . '%' : $value;
        return $wpdb->prepare( "EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key=%s AND m.meta_value {$operator} %s)", $key, $value );
    }

    private function where( array $request ): string {
        global $wpdb;
        $conditions = [ '1=1' ];
        $status = self::input( $request, 'status' );
        if ( in_array( $status, [ 'approved', 'disapproved' ], true ) ) {
            $conditions[] = $this->meta_condition( 'identity_verified_professional', $status );
        } elseif ( $status === 'pending' ) {
            $conditions[] = "NOT EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key='identity_verified_professional' AND m.meta_value IN ('approved','disapproved'))";
        }
        foreach ( [ 'province' => 'residence_province', 'city' => 'residence_city' ] as $filter => $key ) {
            $value = self::input( $request, $filter );
            if ( $value !== '' ) {
                $conditions[] = $this->meta_condition( $key, $value );
            }
        }
        $role = self::input( $request, 'role' );
        if ( $role !== '' ) {
            if ( ! isset( wp_roles()->roles[ $role ] ) ) {
                throw new \InvalidArgumentException( 'نقش نامعتبر است.' );
            }
            $conditions[] = $this->meta_condition( $wpdb->get_blog_prefix() . 'capabilities', '"' . $role . '";b:1', true );
        }
        $from = self::input( $request, 'date_from' );
        $to = self::input( $request, 'date_to' );
        $from = $from !== '' ? self::parse_date( $from ) : '';
        $to = $to !== '' ? self::parse_date( $to ) : '';
        if ( $from && $to && $from > $to ) {
            throw new \InvalidArgumentException( 'تاریخ پایان نباید قبل از شروع باشد.' );
        }
        if ( $from ) {
            $conditions[] = $wpdb->prepare( 'u.user_registered >= %s', $from . ' 00:00:00' );
        }
        if ( $to ) {
            $conditions[] = $wpdb->prepare( 'u.user_registered <= %s', $to . ' 23:59:59' );
        }
        $search = self::input( $request, 'search_text' );
        if ( isset( $request['search'] ) && is_array( $request['search'] ) ) {
            $search = self::input( $request['search'], 'value' );
        }
        $search = CalendarUtils::convertNumbers( mb_substr( $search, 0, 150 ), true );
        foreach ( preg_split( '/\s+/u', trim( $search ), -1, PREG_SPLIT_NO_EMPTY ) as $term ) {
            $like = '%' . $wpdb->esc_like( $term ) . '%';
            $conditions[] = $wpdb->prepare( "(CAST(u.ID AS CHAR) LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key IN ('first_name_fa','last_name_fa','first_name','last_name','billing_phone','digits_phone','national_id') AND m.meta_value LIKE %s))", $like, $like, $like, $like, $like );
        }
        return implode( ' AND ', $conditions );
    }

    private function order( array $request ): string {
        $column = (int) ( $request['sort_column'] ?? 0 );
        $direction = self::input( $request, 'sort_dir' );
        if ( isset( $request['order'][0] ) && is_array( $request['order'][0] ) ) {
            $column = (int) self::input( $request['order'][0], 'column' );
            $direction = self::input( $request['order'][0], 'dir' );
        }
        $columns = [ 0 => 'u.ID', 1 => 'u.display_name', 4 => 'u.user_registered' ];
        return ( $columns[ $column ] ?? 'u.ID' ) . ( $direction === 'asc' ? ' ASC' : ' DESC' ) . ', u.ID DESC';
    }

    private function users( string $where, string $order, int $offset, int $limit ): array {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT u.ID FROM {$wpdb->users} u WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d", $limit, $offset ) );
        if ( ! $ids ) {
            return [];
        }
        $users = get_users( [ 'include' => $ids, 'orderby' => 'include', 'number' => $limit, 'count_total' => false ] );
        update_meta_cache( 'user', $ids );
        return $users;
    }

    public function ajax_rows(): void {
        $this->authorize();
        global $wpdb;
        try {
            $where = $this->where( $_POST );
            $offset = min( 10000000, max( 0, (int) self::input( $_POST, 'start' ) ) );
            $limit = min( 100, max( 1, (int) ( $_POST['length'] ?? 25 ) ) );
            $users = $this->users( $where, $this->order( $_POST ), $offset, $limit );
            $response = [
                'draw' => max( 0, (int) self::input( $_POST, 'draw' ) ),
                'recordsTotal' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
                'recordsFiltered' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE {$where}" ),
                'data' => ( new UserManagement() )->professional_rows( $users ),
            ];
        } catch ( \InvalidArgumentException $error ) {
            $response = [ 'draw' => max( 0, (int) self::input( $_POST, 'draw' ) ), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $error->getMessage() ];
        }
        wp_send_json( $response );
    }

    /** Exports never render role selectors or action forms. */
    private function export_row( $user ): array {
        $id = (int) $user->ID;
        $status = get_user_meta( $id, 'identity_verified_professional', true );
        $province = (string) get_user_meta( $id, 'residence_province', true );
        $name = trim( get_user_meta( $id, 'first_name_fa', true ) . ' ' . get_user_meta( $id, 'last_name_fa', true ) );
        $row = [ (string) $id, $name ?: $user->display_name, $this->provinces()[ $province ] ?? $province,
            (string) get_user_meta( $id, 'residence_city', true ), Date::to_jalali( $user->user_registered ) ];
        foreach ( [ 'personal_photo', 'birth_certificate', 'national_id_card' ] as $key ) {
            $row[] = (string) get_user_meta( $id, $key, true );
        }
        $row[] = $status === 'approved' ? 'مورد تایید' : ( $status === 'disapproved' ? 'مردود' : 'در انتظار' );
        $roles = wp_roles()->roles;
        $row[] = implode( '، ', array_map( static function ( $role ) use ( $roles ) { return $roles[ $role ]['name'] ?? $role; }, $user->roles ) );
        return $row;
    }

    public function export(): void {
        $this->authorize();
        try {
            $where = $this->where( $_POST );
        } catch ( \InvalidArgumentException $error ) {
            wp_die( esc_html( $error->getMessage() ), '', [ 'response' => 400 ] );
        }
        $order = $this->order( $_POST );
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 120 );
        }
        $xlsx = self::input( $_POST, 'format' ) === 'xlsx';
        nocache_headers();
        if ( $xlsx ) {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setRightToLeft( true );
            $sheet->fromArray( $this->headers(), null, 'A1' );
        } else {
            header( 'Content-Type: text/html; charset=UTF-8' );
            echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>هویت حرفه‌ای</title><style>body{font-family:Tahoma}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:5px;overflow-wrap:anywhere}@media print{button{display:none}thead{display:table-header-group}}</style><body><button onclick="window.print()">چاپ / ذخیره PDF</button><h1>هویت حرفه‌ای</h1><table><thead><tr>';
            foreach ( $this->headers() as $header ) {
                echo '<th>' . esc_html( $header ) . '</th>';
            }
            echo '</tr></thead><tbody>';
        }
        $row_number = 2;
        for ( $offset = 0; ; $offset += 200 ) {
            $users = $this->users( $where, $order, $offset, 200 );
            foreach ( $users as $user ) {
                $values = $this->export_row( $user );
                if ( $xlsx ) {
                    foreach ( $values as $column => $value ) {
                        $sheet->setCellValueExplicitByColumnAndRow( $column + 1, $row_number, $value, DataType::TYPE_STRING );
                    }
                } else {
                    echo '<tr><td>' . implode( '</td><td>', array_map( 'esc_html', $values ) ) . '</td></tr>';
                }
                ++$row_number;
            }
            if ( count( $users ) < 200 ) {
                break;
            }
        }
        if ( $xlsx ) {
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="professional-identity.xlsx"' );
            ( new Xlsx( $book ) )->save( 'php://output' );
            $book->disconnectWorksheets();
        } else {
            echo '</tbody></table></body></html>';
        }
        exit;
    }
}
