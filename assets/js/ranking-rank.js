(function ($) {
  'use strict';

  const settings = window.crmRankingsTable || {};
  const ajaxUrl = settings.ajax_url || '';
  const nonce = settings.nonce || '';
  const actions = settings.actions || {};

  const $competition = $('select[name="competition_id"]');
  const $weight = $('select[name="weight_class"]');
  const $table = $('#crm-ranking-table');

  if (!$table.length) {
    return;
  }

  const dataBuilder = function (data) {
    data.action = actions.table || 'crm_ranking_table';
    data.nonce = nonce;
    data.competition_id = parseInt($competition.val(), 10) || 0;
    data.weight_class = parseInt($weight.val(), 10) || 0;
  };

  const dt = $table.DataTable({
    processing: true,
    serverSide: true,
    searching: true,
    paging: true,
    language: {
      url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json'
    },
    ajax: {
      url: ajaxUrl,
      type: 'POST',
      data: dataBuilder
    },
    order: [[4, 'desc']],
    columns: [
      { data: 'position', title: '#', orderable: false, searchable: false, className: 'crm-rank-col--pos' },
      { data: 'user', title: 'کاربر', orderable: true, searchable: true, className: 'crm-rank-col--user' },
      { data: 'gender', title: 'جنسیت', orderable: true, searchable: true, className: 'crm-rank-col--gender' },
      { data: 'weights', title: 'دسته‌های وزنی', orderable: true, searchable: true, className: 'crm-rank-col--weights' },
      { data: 'points', title: 'امتیاز', orderable: true, searchable: false, className: 'crm-rank-col--points' }
    ],
    columnDefs: [
      { targets: 4, render: $.fn.dataTable.render.number(',', '.', 0, '') }
    ],
    createdRow: function (row) {
      $(row).addClass('crm-rank-row');
    }
  });

  $competition.on('change', function () {
    dt.ajax.reload();
  });

  $weight.on('change', function () {
    dt.ajax.reload();
  });
})(jQuery);
