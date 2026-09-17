<?php

namespace IMAOCustom\Services\Admin;

class DataGrid
{
    private const PAGES = [
        'imao-basic-info',
        'imao-prof-identity',
        'crm-self-declarations',
        'crm-clubs',
        'crm-style-committe',
        'crm-points-manager',
        'imao-province-reps',
        'crm-wallet-manager',
    ];

    public function register(): void
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue'], 100);
    }

    public function enqueue(): void
    {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (!in_array($page, self::PAGES, true)) {
            return;
        }

        $url = plugin_dir_url(dirname(__DIR__, 2)) . 'assets/';
        $dt_handle = $this->first_enqueued(['imao-datatables', 'datatables', 'dt-js', 'imao-dt']);
        if ($dt_handle === '') {
            $dt_handle = 'imao-admin-datatables';
            wp_enqueue_script($dt_handle, $url . 'js/jquery.dataTables.min.js', ['jquery'], '1.13.8', true);
        }

        wp_enqueue_style('imao-admin-datatables', $url . 'css/jquery.dataTables.min.css', [], '1.13.8');
        wp_enqueue_style('imao-admin-datatables-buttons', $url . 'css/buttons.dataTables.min.css', ['imao-admin-datatables'], '2.4.2');
        wp_enqueue_style('imao-admin-data-grid', $url . 'css/admin-data-grid.css', ['imao-admin-datatables-buttons'], '1.1.1');
        wp_enqueue_style('imao-jalali-datepicker', 'https://unpkg.com/@majidh1/jalalidatepicker@1.0.0/dist/jalalidatepicker.min.css', [], '1.0.0');

        $buttons_handle = $this->first_enqueued(['imao-datatables-buttons', 'dt-buttons']);
        if ($buttons_handle === '') {
            $buttons_handle = 'imao-admin-datatables-buttons';
            wp_enqueue_script($buttons_handle, $url . 'js/dataTables.buttons.min.js', [$dt_handle], '2.4.2', true);
        }
        $zip_handle = $this->first_enqueued(['imao-jszip', 'dt-jszip']);
        if ($zip_handle === '') {
            $zip_handle = 'imao-admin-jszip';
            wp_enqueue_script($zip_handle, $url . 'js/jszip.min.js', [], '3.10.1', true);
        }
        $html_handle = $this->first_enqueued(['imao-datatables-excel', 'dt-buttons-html5']);
        if ($html_handle === '') {
            $html_handle = 'imao-admin-buttons-html5';
            wp_enqueue_script($html_handle, $url . 'js/buttons.html5.min.js', [$buttons_handle, $zip_handle], '2.4.2', true);
        }
        $print_handle = $this->first_enqueued(['dt-buttons-print']);
        if ($print_handle === '') {
            $print_handle = 'imao-admin-buttons-print';
            wp_enqueue_script($print_handle, $url . 'js/buttons.print.min.js', [$buttons_handle], '2.4.2', true);
        }
        wp_enqueue_script('imao-jalali-datepicker', 'https://unpkg.com/@majidh1/jalalidatepicker@1.0.0/dist/jalalidatepicker.min.js', [], '1.0.0', true);
        wp_enqueue_script('imao-admin-data-grid', $url . 'js/admin-data-grid.js', [$html_handle, $print_handle, 'imao-jalali-datepicker'], '1.1.0', true);
        wp_localize_script('imao-admin-data-grid', 'IMAO_ADMIN_GRID', [
            'page' => $page,
            'title' => wp_get_document_title(),
        ]);
    }

    /** @param array<int,string> $handles */
    private function first_enqueued(array $handles): string
    {
        foreach ($handles as $handle) {
            if (wp_script_is($handle, 'enqueued')) {
                return $handle;
            }
        }
        return '';
    }
}
