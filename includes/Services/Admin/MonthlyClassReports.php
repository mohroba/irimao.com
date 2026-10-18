<?php

namespace IMAOCustom\Services\Admin;

use IMAOCustom\Helpers\Date;
use IMAOCustom\Services\MonthlyTuition;
use Morilog\Jalali\CalendarUtils;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class MonthlyClassReports
{
    private const SLUG = 'imao-monthly-class-reports';
    private const NONCE = 'imao_monthly_class_reports';
    private static bool $registered = false;

    public function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_imao_monthly_class_reports', [$this, 'ajax_rows']);
        add_action('admin_post_imao_monthly_class_export', [$this, 'export']);
    }

    public function add_menu(): void
    {
        add_users_page('گزارش شهریه کلاس‌ها', 'گزارش شهریه کلاس‌ها', 'manage_options', self::SLUG, [$this, 'render_page']);
    }

    public function enqueue(): void
    {
        if (self::input($_GET, 'page') !== self::SLUG || !current_user_can('manage_options')) {
            return;
        }
        $url = plugin_dir_url(dirname(__DIR__, 2)) . 'assets/';
        wp_enqueue_style('imao-dt', $url . 'css/jquery.dataTables.min.css', [], '1.13.8');
        wp_enqueue_script('imao-dt', $url . 'js/jquery.dataTables.min.js', ['jquery'], '1.13.8', true);
        wp_enqueue_style('imao-jdp', $url . 'css/jalalidatepicker.min.css', [], '1.0.0');
        wp_enqueue_script('imao-jdp', $url . 'js/jalalidatepicker.min.js', ['jquery'], '1.0.0', true);
        wp_add_inline_script('imao-dt', $this->script());
        wp_add_inline_script('imao-jdp', 'jQuery(function(){if(window.jalaliDatepicker){jalaliDatepicker.startWatch();}});');
    }

    private static function input(array $source, string $key): string
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? sanitize_text_field(wp_unslash((string) $source[$key])) : '';
    }

    private function authorize(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('دسترسی مجاز نیست.', '', ['response' => 403]);
        }
    }

    private function table(): string
    {
        return MonthlyTuition::table();
    }

    private function table_exists(): bool
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($this->table()))) === $this->table();
    }

    public function render_page(): void
    {
        $this->authorize();
        echo '<div class="wrap" dir="rtl"><h1>گزارش شهریه کلاس‌ها</h1>';
        if (!$this->table_exists()) {
            echo '<div class="notice notice-warning"><p>دفتر شهریه هنوز ایجاد نشده است.</p></div></div>';
            return;
        }
        global $wpdb;
        $classes = $wpdb->get_results('SELECT DISTINCT t.class_id, p.post_title FROM ' . $this->table() . " t LEFT JOIN {$wpdb->posts} p ON p.ID = t.class_id ORDER BY p.post_title, t.class_id");
        echo '<form id="imao-class-report-filters"><p><label>کلاس <select name="class_id"><option value="">همه کلاس‌ها</option>';
        foreach ($classes as $class) {
            echo '<option value="' . (int) $class->class_id . '">' . esc_html($class->post_title ?: '#' . $class->class_id) . '</option>';
        }
        echo '</select></label> <label>وضعیت <select name="status"><option value="">همه</option><option value="paid">پرداخت‌شده</option><option value="partial_refund">بازپرداخت جزئی</option><option value="reversed">برگشت‌خورده</option></select></label> ';
        echo '<label>تاریخ <select name="date_field"><option value="paid_at">پرداخت</option><option value="due_date">سررسید</option></select></label> ';
        echo '<label>از <input name="date_from" data-jdp placeholder="1405/01/01" autocomplete="off"></label> <label>تا <input name="date_to" data-jdp placeholder="1405/12/29" autocomplete="off"></label> ';
        echo '<label>استان <select name="province"><option value="">همه استان‌ها</option>';
        foreach (\IMAOCustom\Helpers\CityMap::get_provinces() as $code => $name) {
            echo '<option value="' . esc_attr($code) . '">' . esc_html($name) . '</option>';
        }
        echo '</select></label> <label>شهرستان <input name="city" placeholder="شهرستان محل سکونت"></label> ';
        echo '<button class="button button-primary" type="submit">اعمال فیلتر</button></p><p class="description">تاریخ شمسی با قالب سال/ماه/روز؛ خروجی شامل تمام ردیف‌های منطبق با فیلتر و جستجو است.</p></form>';
        echo '<p><button class="button imao-class-export" data-format="xlsx">خروجی XLSX</button> <button class="button imao-class-export" data-format="print">چاپ / ذخیره PDF</button></p>';
        // DataTables consumes data-ajax itself and would overwrite the POST configuration.
        echo '<table id="imao-class-report" class="widefat striped" data-report-url="' . esc_url(admin_url('admin-ajax.php')) . '" data-export="' . esc_url(admin_url('admin-post.php')) . '" data-nonce="' . esc_attr(wp_create_nonce(self::NONCE)) . '"><thead><tr>';
        foreach ($this->headers() as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead></table></div>';
    }

    private function script(): string
    {
        return <<<'JS'
jQuery(function ($) {
    const table = $('#imao-class-report');
    if (!table.length || !$.fn.DataTable) return;
    const filters = $('#imao-class-report-filters');
    function values() {
        const result = {};
        filters.serializeArray().forEach(function (item) { result[item.name] = item.value; });
        return result;
    }
    const dt = table.DataTable({
        serverSide: true, processing: true, searchDelay: 450, pageLength: 25,
        lengthMenu: [10, 25, 50, 100], order: [[0, 'desc']],
        language: {search: 'جستجو:', emptyTable: 'داده‌ای یافت نشد', processing: 'در حال دریافت...',
            lengthMenu: 'نمایش _MENU_ ردیف', info: '_START_ تا _END_ از _TOTAL_', infoEmpty: 'بدون ردیف',
            zeroRecords: 'نتیجه‌ای یافت نشد', paginate: {next: 'بعدی', previous: 'قبلی'}},
        columnDefs: [{targets: 11, orderable: false}],
        ajax: {url: table.data('reportUrl'), type: 'POST', data: function (data) {
            Object.assign(data, values(), {action: 'imao_monthly_class_reports', nonce: table.data('nonce')});
        }},
        columns: Array.from({length: 12}, function (_, index) {
            return {data: index, render: $.fn.dataTable.render.text()};
        })
    });
    filters.on('submit', function (event) { event.preventDefault(); dt.ajax.reload(); });
    $('.imao-class-export').on('click', function () {
        const form = $('<form>', {method: 'POST', action: table.data('export'), target: '_blank'});
        const order = dt.order()[0] || [0, 'desc'];
        const data = Object.assign(values(), {action: 'imao_monthly_class_export', nonce: table.data('nonce'),
            format: $(this).data('format'), search_text: dt.search(), sort_column: order[0], sort_dir: order[1]});
        Object.keys(data).forEach(function (key) { $('<input>', {type: 'hidden', name: key, value: data[key]}).appendTo(form); });
        form.appendTo(document.body).trigger('submit').remove();
    });
});
JS;
    }

    /** Normalize both Jalali and Gregorian input without allowing date rollover. */
    private function parse_date(string $value): string
    {
        $value = str_replace('-', '/', CalendarUtils::convertNumbers(trim($value), true));
        if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~D', $value, $matches)) {
            throw new \InvalidArgumentException('قالب تاریخ نامعتبر است.');
        }
        [$year, $month, $day] = array_map('intval', array_slice($matches, 1));
        if ($year < 1700) {
            if ($year < 1200 || !CalendarUtils::checkDate($year, $month, $day, true)) {
                throw new \InvalidArgumentException('تاریخ شمسی نامعتبر است.');
            }
            [$year, $month, $day] = CalendarUtils::toGregorian($year, $month, $day);
        }
        if (!checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException('تاریخ نامعتبر است.');
        }
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    /** Each metadata join selects one canonical row, even with duplicate usermeta. */
    private function joins(): string
    {
        global $wpdb;
        $sql = ' FROM ' . $this->table() . " t LEFT JOIN {$wpdb->users} u ON u.ID = t.user_id LEFT JOIN {$wpdb->posts} p ON p.ID = t.class_id";
        foreach (['fn' => 'first_name_fa', 'ln' => 'last_name_fa', 'first' => 'first_name', 'last' => 'last_name', 'phone' => 'billing_phone', 'nid' => 'national_id', 'province' => 'residence_province', 'city' => 'residence_city'] as $alias => $key) {
            $sql .= " LEFT JOIN {$wpdb->usermeta} {$alias} ON {$alias}.umeta_id = (SELECT MIN(m.umeta_id) FROM {$wpdb->usermeta} m WHERE m.user_id = t.user_id AND m.meta_key = '{$key}')";
        }
        return $sql;
    }

    private function name_sql(): string
    {
        return "COALESCE(NULLIF(TRIM(CONCAT_WS(' ', COALESCE(NULLIF(fn.meta_value, ''), first.meta_value), COALESCE(NULLIF(ln.meta_value, ''), last.meta_value))), ''), NULLIF(u.display_name, ''), CONCAT('#', t.user_id))";
    }

    private function where(array $request): string
    {
        global $wpdb;
        $parts = ['1=1'];
        foreach (['province', 'city'] as $key) {
            $value = self::input($request, $key);
            if ($value !== '') {
                $parts[] = $wpdb->prepare("{$key}.meta_value = %s", $value);
            }
        }
        $class = self::input($request, 'class_id');
        if ($class !== '') {
            if (!ctype_digit($class) || (int) $class < 1) {
                throw new \InvalidArgumentException('شناسه کلاس نامعتبر است.');
            }
            $parts[] = $wpdb->prepare('t.class_id = %d', (int) $class);
        }
        $status = self::input($request, 'status');
        if ($status !== '') {
            if (!in_array($status, ['paid', 'partial_refund', 'reversed'], true)) {
                throw new \InvalidArgumentException('وضعیت نامعتبر است.');
            }
            $parts[] = $wpdb->prepare('t.status = %s', $status);
        }
        $field = self::input($request, 'date_field');
        if ($field !== '' && !in_array($field, ['paid_at', 'due_date'], true)) {
            throw new \InvalidArgumentException('نوع تاریخ نامعتبر است.');
        }
        $field = $field === 'due_date' ? 'due_date' : 'paid_at';
        $from = self::input($request, 'date_from');
        $to = self::input($request, 'date_to');
        $from = $from === '' ? '' : $this->parse_date($from);
        $to = $to === '' ? '' : $this->parse_date($to);
        if ($from !== '' && $to !== '' && $from > $to) {
            throw new \InvalidArgumentException('تاریخ شروع باید پیش از پایان باشد.');
        }
        if ($from !== '') {
            $parts[] = $wpdb->prepare("t.{$field} >= %s", $from);
        }
        if ($to !== '') {
            $next = (new \DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d');
            $parts[] = $wpdb->prepare("t.{$field} < %s", $next);
        }
        $search = isset($request['search']) && is_array($request['search']) ? self::input($request['search'], 'value') : self::input($request, 'search_text');
        if ($search !== '') {
            $search = CalendarUtils::convertNumbers($search, true);
            $like = '%' . $wpdb->esc_like($search) . '%';
            $terms = [];
            foreach ([$this->name_sql(), 'u.display_name', 'u.user_email', 'p.post_title', 'phone.meta_value', 'nid.meta_value', 't.order_id', 't.order_item_id', 't.user_id', 't.class_id', 't.status'] as $column) {
                $terms[] = $wpdb->prepare("{$column} LIKE %s", $like);
            }
            $parts[] = '(' . implode(' OR ', $terms) . ')';
        }
        return ' WHERE ' . implode(' AND ', $parts);
    }

    private function order(array $request): string
    {
        $columns = ['t.id', 't.order_id', 't.order_item_id', 'p.post_title', $this->name_sql(), 'phone.meta_value', 'nid.meta_value', 't.paid_at', 't.due_date', 't.amount', 't.status'];
        $order = isset($request['order'][0]) && is_array($request['order'][0]) ? $request['order'][0] : [];
        $index = (int) ($order['column'] ?? self::input($request, 'sort_column'));
        $direction = strtolower(self::input($order, 'dir') ?: self::input($request, 'sort_dir')) === 'asc' ? 'ASC' : 'DESC';
        return ' ORDER BY ' . ($columns[$index] ?? 't.id') . ' ' . $direction . ', t.id ' . $direction;
    }

    private function select_sql(): string
    {
        return 'SELECT t.*, p.post_title AS class_title, ' . $this->name_sql() . ' AS student_name, phone.meta_value AS mobile, nid.meta_value AS national_id';
    }

    public function ajax_rows(): void
    {
        $this->authorize();
        check_ajax_referer(self::NONCE, 'nonce');
        $draw = max(0, (int) self::input($_POST, 'draw'));
        try {
            $where = $this->where($_POST);
            if (!$this->table_exists()) {
                wp_send_json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
                return;
            }
            global $wpdb;
            $total = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $this->table());
            $filtered = (int) $wpdb->get_var('SELECT COUNT(*)' . $this->joins() . $where);
            $length = max(1, min(100, (int) (self::input($_POST, 'length') ?: 25)));
            $offset = max(0, (int) self::input($_POST, 'start'));
            $sql = $this->select_sql() . $this->joins() . $where . $this->order($_POST) . $wpdb->prepare(' LIMIT %d OFFSET %d', $length, $offset);
            $rows = $wpdb->get_results($sql, ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Report query failed.');
            }
            wp_send_json(['draw' => $draw, 'recordsTotal' => $total, 'recordsFiltered' => $filtered, 'data' => array_map([$this, 'format_row'], $rows ?: [])]);
        } catch (\InvalidArgumentException $error) {
            wp_send_json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => $error->getMessage()]);
        } catch (\Throwable $error) {
            wp_send_json(['draw' => $draw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => [], 'error' => 'دریافت گزارش انجام نشد.']);
        }
    }

    private function headers(): array
    {
        return ['شناسه', 'سفارش', 'ردیف سفارش', 'کلاس', 'نام ورزشکار', 'موبایل', 'کد ملی', 'تاریخ پرداخت', 'سررسید', 'مبلغ', 'وضعیت', 'تسهیم ثبت‌شده (JSON)'];
    }

    private function export_headers(): array
    {
        $headers = $this->headers();
        foreach (['معرف', 'مربی', 'باشگاه'] as $role) {
            foreach (['شناسه گیرنده', 'درصد سهم', 'مبلغ سهم اولیه', 'مبلغ اعتبار فعلی'] as $label) {
                $headers[] = $role . ' - ' . $label;
            }
        }
        $headers[] = 'مبلغ بازپرداخت';
        return $headers;
    }

    private function format_row(array $row, bool $audit = false): array
    {
        // Preserve the producer's snapshot, including all payout recipients and shares.
        $snapshot = json_decode((string) ($row['snapshot'] ?? ''), true);
        $data = is_array($snapshot) ? $snapshot : [];
        if ($audit) {
            $snapshot = is_array($snapshot) ? wp_json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) ($row['snapshot'] ?? '');
        } else {
            $summary = [];
            foreach (['referrer' => 'معرف', 'coach' => 'مربی', 'club' => 'باشگاه'] as $role => $label) {
                $summary[] = $label . ' #' . (int) ($data['recipients'][$role] ?? 0) . ': '
                    . (float) ($data['shares'][$role] ?? 0) . '% — ' . (float) ($data['credited'][$role] ?? 0);
            }
            $snapshot = implode(' | ', $summary);
        }
        $values = [
            $row['id'], $row['order_id'], $row['order_item_id'], $row['class_title'] ?: '#' . $row['class_id'],
            $row['student_name'], $row['mobile'] ?? '', $row['national_id'] ?? '',
            empty($row['paid_at']) ? '' : Date::to_jalali($row['paid_at']),
            empty($row['due_date']) ? '' : Date::to_jalali($row['due_date'], 'Y/m/d'),
            $row['amount'], ['paid' => 'پرداخت‌شده', 'partial_refund' => 'بازپرداخت جزئی', 'reversed' => 'برگشت‌خورده'][$row['status']] ?? $row['status'], $snapshot,
        ];
        if ($audit) {
            foreach (['referrer', 'coach', 'club'] as $role) {
                foreach (['recipients', 'shares', 'amounts', 'credited'] as $key) {
                    $value = $data[$key][$role] ?? '';
                    $values[] = is_scalar($value) ? $value : '';
                }
            }
            $refunded = $data['refunded_amount'] ?? '';
            $values[] = is_scalar($refunded) ? $refunded : '';
        }
        return array_map('strval', $values);
    }

    /** Export every matching row in bounded database batches, independent of DataTables paging. */
    private function export_rows(array $request, bool $audit = true): \Generator
    {
        global $wpdb;
        $base = $this->select_sql() . $this->joins() . $this->where($request) . $this->order($request);
        for ($offset = 0; ; $offset += 500) {
            $rows = $wpdb->get_results($base . $wpdb->prepare(' LIMIT %d OFFSET %d', 500, $offset), ARRAY_A);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('Export query failed.');
            }
            foreach ($rows ?: [] as $row) {
                yield $this->format_row($row, $audit);
            }
            if (count($rows ?: []) < 500) {
                break;
            }
        }
    }

    public function export(): void
    {
        $this->authorize();
        check_admin_referer(self::NONCE, 'nonce');
        try {
            $this->where($_POST);
        } catch (\InvalidArgumentException $error) {
            wp_die(esc_html($error->getMessage()), '', ['response' => 400]);
        }
        $format = self::input($_POST, 'format');
        if (!in_array($format, ['xlsx', 'print'], true)) {
            wp_die('قالب خروجی نامعتبر است.', '', ['response' => 400]);
        }
        if (!$this->table_exists()) {
            wp_die('دفتر شهریه هنوز ایجاد نشده است.', '', ['response' => 503]);
        }
        nocache_headers();
        if ($format === 'xlsx') {
            $book = new Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setRightToLeft(true);
            $line = 1;
            $write = static function (array $row) use ($sheet, &$line): void {
                foreach ($row as $index => $value) {
                    // Explicit strings preserve IDs/decimal precision and prevent formula injection.
                    $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($index + 1) . $line, (string) $value, DataType::TYPE_STRING);
                }
                ++$line;
            };
            $write($this->export_headers());
            foreach ($this->export_rows($_POST) as $row) {
                $write($row);
            }
            $sheet->freezePane('A2');
            $sheet->setAutoFilter('A1:' . Coordinate::stringFromColumnIndex(count($this->export_headers())) . ($line - 1));
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="monthly-class-tuition.xlsx"');
            (new Xlsx($book))->save('php://output');
            $book->disconnectWorksheets();
            exit;
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>گزارش شهریه کلاس‌ها</title><style>body{font-family:Tahoma,sans-serif}table{border-collapse:collapse;width:100%;font-size:11px}td,th{border:1px solid #aaa;padding:5px;overflow-wrap:anywhere}thead{display:table-header-group}@page{size:A4 landscape;margin:10mm}@media print{button{display:none}tr{break-inside:avoid}}</style></head><body><h1>گزارش شهریه کلاس‌ها</h1><button onclick="window.print()">چاپ / ذخیره PDF</button><table><thead><tr>';
        foreach ($this->headers() as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($this->export_rows($_POST, false) as $row) {
            echo '<tr>';
            foreach ($row as $value) {
                echo '<td>' . esc_html($value) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}
