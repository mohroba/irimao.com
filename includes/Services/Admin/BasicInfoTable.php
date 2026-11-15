<?php

namespace IMAOCustom\Services\Admin;

use IMAOCustom\Helpers\CityMap;
use IMAOCustom\Helpers\Wallet as WalletHelper;
use Morilog\Jalali\CalendarUtils;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Bounded user queries shared by the two administrative directories. */
class BasicInfoTable {
    private bool $wallet;

    public function __construct( bool $wallet = false ) {
        $this->wallet = $wallet;
    }

    public function register(): void {
        if ( ! $this->wallet ) {
            add_action( 'wp_ajax_imao_basic_rows', [ $this, 'ajax_rows' ] );
        }
        add_action( 'admin_post_' . $this->action() . '_export', [ $this, 'export' ] );
    }

    private function action(): string {
        return $this->wallet ? 'imao_wallet' : 'imao_basic';
    }

    private static function input( array $request, string $key ): string {
        return isset( $request[$key] ) && is_scalar( $request[$key] )
            ? sanitize_text_field( wp_unslash( (string) $request[$key] ) ) : '';
    }

    private function authorize(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'دسترسی مجاز نیست.', '', [ 'response' => 403 ] );
        }
        check_admin_referer( $this->action(), 'nonce' );
    }

    public function render_filters( int $user_id = 0 ): void {
        echo '<form class="imao-grid-toolbar imao-directory-filters">';
        echo '<input type="hidden" name="user_id" value="' . esc_attr( $user_id ) . '">';
        echo '<label>وضعیت <select name="status"><option value="">همه</option><option value="active">فعال</option><option value="banned">مسدود</option></select></label> ';
        echo '<label>استان <select name="province"><option value="">همه</option>';
        foreach ( CityMap::get_provinces() as $code => $label ) {
            echo '<option value="' . esc_attr( $code ) . '">' . esc_html( $label ) . '</option>';
        }
        echo '</select></label> <label>شهرستان <input name="city"></label> <label>نقش <select name="role"><option value="">همه</option>';
        foreach ( wp_roles()->roles as $role => $details ) {
            echo '<option value="' . esc_attr( $role ) . '">' . esc_html( $details['name'] ?? $role ) . '</option>';
        }
        echo '</select></label> <label>تاریخ عضویت از <input name="date_from" data-jdp autocomplete="off"></label> <label>تا <input name="date_to" data-jdp autocomplete="off"></label> ';
        if ( ! $this->wallet ) {
            echo '<label>تاریخ تولد از <input name="birth_from" data-jdp autocomplete="off"></label> <label>تا <input name="birth_to" data-jdp autocomplete="off"></label> ';
        }
        echo '<button class="button button-primary">اعمال فیلتر</button> <button type="reset" class="button">پاک کردن فیلترها</button></form>';
        echo '<p><button class="button imao-directory-export" data-format="xlsx">خروجی XLSX</button> <button class="button imao-directory-export" data-format="print">خروجی PDF / چاپ</button></p>';
        echo '<p class="description">خروجی شامل همه نتایج منطبق است، نه فقط صفحه جاری.</p>';
    }

    public function attributes(): string {
        return ' data-directory-url="' . esc_url( admin_url( 'admin-ajax.php' ) ) . '" data-export-url="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-export-action="' . esc_attr( $this->action() . '_export' ) . '" data-nonce="' . esc_attr( wp_create_nonce( $this->action() ) ) . '"';
    }

    private function meta( string $key ): string {
        global $wpdb;
        // First value matches get_user_meta(single), even with legacy duplicates.
        return $wpdb->prepare( "(SELECT m.meta_value FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key=%s ORDER BY m.umeta_id LIMIT 1)", $key );
    }

    private function where( array $request ): string {
        global $wpdb;
        $conditions = [ '1=1' ];
        $id = self::input( $request, 'user_id' );
        if ( $id !== '' && $id !== '0' ) {
            $conditions[] = $wpdb->prepare( 'u.ID=%d', max( 0, (int) $id ) );
        }
        $status = self::input( $request, 'status' );
        if ( $status !== '' && ! in_array( $status, [ 'active', 'banned' ], true ) ) {
            throw new \InvalidArgumentException( 'وضعیت نامعتبر است.' );
        }
        if ( $status !== '' ) {
            $conditions[] = 'COALESCE(' . $this->meta( 'imao_banned' ) . ", '') " . ( $status === 'banned' ? 'NOT IN' : 'IN' ) . " ('','0')";
        }
        foreach ( [ 'province' => 'residence_province', 'city' => 'residence_city' ] as $filter => $key ) {
            $value = self::input( $request, $filter );
            if ( $value !== '' ) {
                $conditions[] = $wpdb->prepare( $this->meta( $key ) . '=%s', $value );
            }
        }
        $role = self::input( $request, 'role' );
        if ( $role !== '' ) {
            if ( ! isset( wp_roles()->roles[$role] ) ) {
                throw new \InvalidArgumentException( 'نقش نامعتبر است.' );
            }
            $conditions[] = $wpdb->prepare( $this->meta( $wpdb->get_blog_prefix() . 'capabilities' ) . ' LIKE %s', '%' . $wpdb->esc_like( '"' . $role . '";b:1' ) . '%' );
        }
        $from = self::input( $request, 'date_from' );
        $to = self::input( $request, 'date_to' );
        $from = $from !== '' ? ProfessionalIdentityTable::parse_date( $from ) : '';
        $to = $to !== '' ? ProfessionalIdentityTable::parse_date( $to ) : '';
        if ( $from && $to && $from > $to ) {
            throw new \InvalidArgumentException( 'تاریخ پایان نباید قبل از شروع باشد.' );
        }
        if ( $from ) {
            $conditions[] = $wpdb->prepare( 'u.user_registered >= %s', $from . ' 00:00:00' );
        }
        if ( $to ) {
            $conditions[] = $wpdb->prepare( 'u.user_registered <= %s', $to . ' 23:59:59' );
        }
        if ( ! $this->wallet ) {
            $birth_from = self::input( $request, 'birth_from' );
            $birth_to = self::input( $request, 'birth_to' );
            $bounds = [];
            foreach ( [ $birth_from, $birth_to ] as $value ) {
                if ( $value === '' ) {
                    $bounds[] = '';
                    continue;
                }
                $gregorian = ProfessionalIdentityTable::parse_date( $value );
                $parts = array_map( 'intval', explode( '-', $gregorian ) );
                $bounds[] = vsprintf( '%04d/%02d/%02d', CalendarUtils::toJalali( ...$parts ) );
            }
            if ( $bounds[0] && $bounds[1] && $bounds[0] > $bounds[1] ) {
                throw new \InvalidArgumentException( 'تاریخ پایان نباید قبل از شروع باشد.' );
            }
            if ( $bounds[0] || $bounds[1] ) {
                $birth = 'REPLACE(' . $this->meta( 'birth_date' ) . ", '-', '/')";
                foreach ( [ '۰۱۲۳۴۵۶۷۸۹', '٠١٢٣٤٥٦٧٨٩' ] as $digits ) {
                    foreach ( preg_split( '//u', $digits, -1, PREG_SPLIT_NO_EMPTY ) as $digit => $character ) {
                        $birth = $wpdb->prepare( 'REPLACE(' . $birth . ', %s, %s)', $character, (string) $digit );
                    }
                }
                $birth = "CONCAT(SUBSTRING_INDEX({$birth},'/',1),'/',LPAD(SUBSTRING_INDEX(SUBSTRING_INDEX({$birth},'/',2),'/',-1),2,'0'),'/',LPAD(SUBSTRING_INDEX({$birth},'/',-1),2,'0'))";
                if ( $bounds[0] ) {
                    $conditions[] = $wpdb->prepare( $birth . ' >= %s', $bounds[0] );
                }
                if ( $bounds[1] ) {
                    $conditions[] = $wpdb->prepare( $birth . ' <= %s', $bounds[1] );
                }
            }
        }
        $search = isset( $request['search'] ) && is_array( $request['search'] ) ? self::input( $request['search'], 'value' ) : self::input( $request, 'search_text' );
        $search = CalendarUtils::convertNumbers( mb_substr( $search, 0, 150 ), true );
        $keys = array_merge( array_keys( UserManagement::basic_fields() ), [ 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'digits_phone', 'card_number', 'iban' ] );
        $key_sql = implode( ',', array_map( static function ( $key ) use ( $wpdb ) { return $wpdb->prepare( '%s', $key ); }, $keys ) );
        foreach ( preg_split( '/\s+/u', trim( $search ), -1, PREG_SPLIT_NO_EMPTY ) as $term ) {
            $like = '%' . $wpdb->esc_like( $term ) . '%';
            $conditions[] = $wpdb->prepare( "(CAST(u.ID AS CHAR) LIKE %s OR u.display_name LIKE %s OR u.user_login LIKE %s OR u.user_email LIKE %s OR EXISTS (SELECT 1 FROM {$wpdb->usermeta} m WHERE m.user_id=u.ID AND m.meta_key IN ({$key_sql}) AND m.meta_value LIKE %s))", $like, $like, $like, $like, $like );
        }
        return implode( ' AND ', $conditions );
    }

    private function order( array $request ): string {
        $order = isset( $request['order'][0] ) && is_array( $request['order'][0] ) ? $request['order'][0] : [];
        $column = (int) ( self::input( $order, 'column' ) ?: self::input( $request, 'sort_column' ) );
        $direction = self::input( $order, 'dir' ) ?: self::input( $request, 'sort_dir' );
        $columns = $this->wallet ? [ 0 => 'u.ID', 6 => 'u.user_registered', 7 => 'CAST(COALESCE(' . $this->meta( WalletHelper::get_meta_key() ) . ", '0') AS DECIMAL(24,6))" ] : [ 0 => 'u.ID', 1 => 'u.display_name', 3 => 'u.user_registered' ];
        return ( $columns[$column] ?? 'u.ID' ) . ( $direction === 'asc' ? ' ASC' : ' DESC' ) . ', u.ID DESC';
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

    public function page( array $request ): array {
        global $wpdb;
        $where = $this->where( $request );
        $offset = max( 0, (int) self::input( $request, 'start' ) );
        $limit = min( 100, max( 1, (int) ( self::input( $request, 'length' ) ?: 25 ) ) );
        return [
            'draw' => max( 0, (int) self::input( $request, 'draw' ) ),
            'recordsTotal' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" ),
            'recordsFiltered' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users} u WHERE {$where}" ),
            'users' => $this->users( $where, $this->order( $request ), $offset, $limit ),
        ];
    }

    public function ajax_rows(): void {
        $this->authorize();
        try {
            $response = $this->page( $_POST );
            $response['data'] = ( new UserManagement() )->basic_rows( $response['users'] );
            unset( $response['users'] );
        } catch ( \InvalidArgumentException $error ) {
            $response = [ 'draw' => max( 0, (int) self::input( $_POST, 'draw' ) ), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $error->getMessage() ];
        }
        wp_send_json( $response );
    }

    public function export(): void {
        $this->authorize();
        try {
            $where = $this->where( $_POST );
        } catch ( \InvalidArgumentException $error ) {
            wp_die( esc_html( $error->getMessage() ), '', [ 'response' => 400 ] );
        }
        $order = $this->order( $_POST );
        $headers = $this->wallet ? [ 'ID', 'نام', 'نام خانوادگی', 'استان', 'شهرستان', 'وضعیت', 'تاریخ عضویت', 'موجودی', 'شماره کارت', 'شماره شبا' ] : array_merge( [ 'ID', 'نام', 'وضعیت', 'تاریخ عضویت' ], array_values( UserManagement::basic_fields() ) );
        $xlsx = self::input( $_POST, 'format' ) === 'xlsx';
        nocache_headers();
        if ( $xlsx ) {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setRightToLeft( true );
            $sheet->fromArray( $headers, null, 'A1' );
        } else {
            header( 'Content-Type: text/html; charset=UTF-8' );
            echo '<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><title>خروجی کاربران</title><style>body{font-family:Tahoma}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:5px;overflow-wrap:anywhere}@media print{button{display:none}thead{display:table-header-group}}</style><body><button onclick="window.print()">چاپ / ذخیره PDF</button><table><thead><tr><th>' . implode( '</th><th>', array_map( 'esc_html', $headers ) ) . '</th></tr></thead><tbody>';
        }
        $row_number = 2;
        $renderer = $this->wallet ? new \IMAOCustom\Services\Endpoints\Wallet() : new UserManagement();
        foreach ( $this->export_batches( $where, $order ) as $offset => $users ) {
            $rows = $this->wallet ? $renderer->wallet_manager_rows( $users, $offset, true ) : $renderer->basic_rows( $users, true );
            foreach ( $rows as $values ) {
                if ( $xlsx ) {
                    foreach ( array_values( $values ) as $column => $value ) {
                        $sheet->setCellValueExplicitByColumnAndRow( $column + 1, $row_number, (string) $value, DataType::TYPE_STRING );
                    }
                } else {
                    echo '<tr><td>' . implode( '</td><td>', array_map( 'esc_html', $values ) ) . '</td></tr>';
                }
                ++$row_number;
            }
        }
        if ( $xlsx ) {
            header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
            header( 'Content-Disposition: attachment; filename="' . $this->action() . '.xlsx"' );
            ( new Xlsx( $book ) )->save( 'php://output' );
            $book->disconnectWorksheets();
        } else {
            echo '</tbody></table></body></html>';
        }
        exit;
    }

    /** Never hydrate the entire matching directory at once. */
    private function export_batches( string $where, string $order ): \Generator {
        for ( $offset = 0; ; $offset += 200 ) {
            $users = $this->users( $where, $order, $offset, 200 );
            yield $offset => $users;
            if ( count( $users ) < 200 ) {
                return;
            }
        }
    }
}
