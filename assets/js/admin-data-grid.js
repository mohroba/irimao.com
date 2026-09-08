(function ($) {
    'use strict';

    const tableSelectors = [
        '#crm-basic-table', '#crm-prof-table', '#crm-selfdec-table', '#club-table',
        '#crm-stylecomm-table', '#crm-ranking-table', '#imao-province-table',
        '#imao-city-table', '#imao-city-requests-table', '#crm-wallet-table'
    ];

    const text = {
        search: 'جستجوی همه ستون‌ها:',
        lengthMenu: 'نمایش _MENU_ ردیف',
        info: 'نمایش _START_ تا _END_ از _TOTAL_ رکورد',
        infoEmpty: 'رکوردی موجود نیست',
        zeroRecords: 'رکوردی مطابق فیلترها یافت نشد',
        paginate: { previous: 'قبلی', next: 'بعدی', first: 'اول', last: 'آخر' }
    };

    function normalized(value) {
        return String(value || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[\u200c\s]+/g, ' ').trim();
    }

    function cleanCell(value) {
        return $('<div>').html(value == null ? '' : value).text().replace(/\s+/g, ' ').trim();
    }

    function columnMap($table) {
        const map = {};
        $table.find('thead th').each(function (index) {
            const heading = normalized($(this).text());
            if (/وضعیت/.test(heading)) map.status = index;
            if (/استان|هیئت/.test(heading) && (map.province == null || /سکونت/.test(heading))) map.province = index;
            if (/شهر|شهرستان/.test(heading) && (map.city == null || /سکونت/.test(heading))) map.city = index;
            if (/تاریخ/.test(heading)) {
                map.dates = map.dates || [];
                map.dates.push({ index, label: heading });
            }
        });
        return map;
    }

    function uniqueValues(api, index) {
        if (index == null) return [];
        const values = [];
        api.column(index).data().each((value) => {
            const clean = cleanCell(value);
            if (clean && clean !== '—' && !values.includes(clean)) values.push(clean);
        });
        return values.sort((a, b) => a.localeCompare(b, 'fa'));
    }

    function selectField(label, key, values) {
        const $field = $('<div class="imao-grid-field">');
        const $select = $('<select>').attr('data-filter', key).append($('<option>').val('').text('همه'));
        values.forEach((value) => $select.append($('<option>').val(value).text(value)));
        return $field.append($('<label>').text(label), $select);
    }

    function exactSearch(api, index, value) {
        if (index == null) return;
        api.column(index).search(value ? '^' + $.fn.dataTable.util.escapeRegex(value) + '$' : '', true, false).draw();
    }

    function setupFilters(api, $table, $toolbar) {
        const map = columnMap($table);
        ['status', 'province', 'city'].forEach((key) => {
            if (map[key] == null) return;
            const labels = { status: 'وضعیت', province: 'استان', city: 'شهر / شهرستان' };
            const $field = selectField(labels[key], key, uniqueValues(api, map[key]));
            $field.find('select').on('change', function () { exactSearch(api, map[key], this.value); });
            $toolbar.append($field);
        });

        (map.dates || []).forEach((date, position) => {
            const key = 'date-' + position;
            const $from = $('<input type="text" inputmode="numeric" placeholder="مثال: 1405/01/01">').attr('data-filter', key + '-from');
            const $to = $('<input type="text" inputmode="numeric" placeholder="مثال: 1405/12/29">').attr('data-filter', key + '-to');
            $toolbar.append($('<div class="imao-grid-field">').append($('<label>').text(date.label + ' از'), $from));
            $toolbar.append($('<div class="imao-grid-field">').append($('<label>').text(date.label + ' تا'), $to));

            const predicate = function (settings, row) {
                if (settings.nTable !== $table[0]) return true;
                const value = normalized(cleanCell(row[date.index])).replace(/-/g, '/');
                const from = normalized($from.val()).replace(/-/g, '/');
                const to = normalized($to.val()).replace(/-/g, '/');
                if (!value || value === '—') return !from && !to;
                return (!from || value >= from) && (!to || value <= to);
            };
            $.fn.dataTable.ext.search.push(predicate);
            $from.add($to).on('input change', () => api.draw());
        });

        return map;
    }

    function exportColumns(api) {
        const indexes = [];
        api.columns().every(function (index) {
            const title = normalized($(this.header()).text());
            if (!/عملیات|اقدام|مبلغ$|پرداخت بابت/.test(title)) indexes.push(index);
        });
        return indexes;
    }

    function buttonsFor(api, title) {
        const columns = exportColumns(api);
        const options = { columns, modifier: { search: 'applied', order: 'applied', page: 'all' }, stripHtml: true };
        return new $.fn.dataTable.Buttons(api, {
            buttons: [
                { extend: 'excelHtml5', text: 'خروجی XLSX', title, exportOptions: options },
                {
                    extend: 'print', text: 'خروجی PDF / چاپ', title, exportOptions: options,
                    customize: function (win) {
                        $(win.document.documentElement).attr('dir', 'rtl');
                        $(win.document.body).css({ direction: 'rtl', fontFamily: 'Tahoma, Arial, sans-serif' });
                        $(win.document.body).find('table').css({ direction: 'rtl', textAlign: 'right', width: '100%' });
                    }
                }
            ]
        });
    }

    function enhance($table) {
        if ($table.data('imaoGridReady')) return;
        $table.data('imaoGridReady', true);

        let api;
        if ($.fn.dataTable.isDataTable($table[0])) {
            api = $table.DataTable();
        } else {
            api = $table.DataTable({ pageLength: 25, stateSave: true, autoWidth: false, deferRender: true, language: text });
        }

        const $wrapper = $(api.table().container());
        if (!$wrapper.parent().hasClass('imao-grid-shell')) $wrapper.wrap('<div class="imao-grid-shell"></div>');
        const $shell = $wrapper.parent();
        const $toolbar = $('<div class="imao-grid-toolbar" aria-label="فیلترهای جدول">');
        setupFilters(api, $table, $toolbar);

        const $actions = $('<div class="imao-grid-actions">');
        buttonsFor(api, normalized($('h1').first().text()) || document.title).container().appendTo($actions);
        $('<button type="button" class="imao-grid-reset">پاک کردن فیلترها</button>').on('click', function () {
            $toolbar.find('input, select').val('');
            api.search('').columns().search('').draw();
        }).appendTo($actions);
        $toolbar.append($actions).prependTo($shell);
    }

    $(window).on('load', function () {
        if (!$.fn.DataTable || !$.fn.dataTable.Buttons) return;
        window.setTimeout(() => $(tableSelectors.join(',')).each(function () { enhance($(this)); }), 100);
    });
}(jQuery));
