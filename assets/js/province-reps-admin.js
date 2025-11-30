jQuery(function ($) {
  const $tables = $('.imao-reps-table');
  $tables.each(function () {
    $(this).DataTable({
      dom: 'Bfrtip',
      buttons: [
        {
          extend: 'excelHtml5',
          text: 'خروجی Excel'
        }
      ],
      language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/fa.json'
      },
      pageLength: 20,
      responsive: true
    });
  });

  $('.crm-select2').select2({ width: 'resolve' });
});
