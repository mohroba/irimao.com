jQuery(function ($) {
  const tableConfig = {
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
  };

  const $provinceTable = $('#imao-province-table');
  const $cityTable = $('#imao-city-table');
  const $cityRequestsTable = $('#imao-city-requests-table');
  if ($provinceTable.length) {
    $provinceTable.DataTable(tableConfig);
  }
  if ($cityTable.length) {
    $cityTable.DataTable(tableConfig);
  }
  if ($cityRequestsTable.length) {
    $cityRequestsTable.DataTable(tableConfig);
  }

  $('.crm-select2').select2({ width: 'resolve' });

  $(document).on('click', '.delete-btn', function (e) {
    if (!confirm('حذف این رکورد قطعی است. ادامه می‌دهید؟')) {
      e.preventDefault();
    }
  });

  $(document).on('click', '.reject-btn', function (e) {
    const reason = prompt('لطفاً دلیل رد را وارد کنید:');
    if (reason === null) {
      e.preventDefault();
      return;
    }
    if (!reason.trim()) {
      alert('دلیل رد الزامی است.');
      e.preventDefault();
      return;
    }
    const $row = $(this).closest('tr');
    $row.find('input[name*=\"[reason]\"]').val(reason.trim());
  });

  const data = window.IMAOREPS || { cityMap: {} };

  function fillCityOptions(provinceCode, selectedValue) {
    const $city = $('#edit_city_name');
    $city.empty();
    $city.append(new Option('— انتخاب کنید —', ''));
    const cities = data.cityMap[provinceCode] || [];
    cities.forEach(city => {
      const opt = new Option(city, city, false, city === selectedValue);
      $city.append(opt);
    });
    $city.trigger('change.select2');
  }

  $('#edit_city_province').on('change', function () {
    fillCityOptions($(this).val(), '');
  });

  $(document).on('click', '.edit-btn', function (e) {
    e.preventDefault();
    const $btn = $(this);
    const type = $btn.data('type');
    $('.imao-edit-panel').hide();

    if (type === 'province') {
      $('#edit_province_id').val($btn.data('id'));
      $('#edit_province_user').val($btn.data('user')).trigger('change');
      $('#edit_province_code').val($btn.data('province')).trigger('change');
      $('#edit_province_gender').val($btn.data('gender')).trigger('change');
      $('#edit_province_status').val($btn.data('status')).trigger('change');
      $('#province-edit-panel').show();
    } else if (type === 'city') {
      const province = $btn.data('province');
      $('#edit_city_id').val($btn.data('id'));
      $('#edit_city_user').val($btn.data('user')).trigger('change');
      $('#edit_city_province').val(province).trigger('change');
      fillCityOptions(province, $btn.data('city'));
      $('#edit_city_gender').val($btn.data('gender')).trigger('change');
      $('#edit_city_status').val($btn.data('status')).trigger('change');
      $('#city-edit-panel').show();
    }
  });
});
