jQuery(function ($) {
  const $national = $('#city_national_id');
  const $name = $('#city_user_name');
  const $userId = $('#city_user_id');
  const $checkBtn = $('#city_national_check');
  const $citySelect = $('#city_name');
  const $genderSelect = $('#city_gender');
  const $submitBtn = $('#city_rep_submit');
  const $detailFields = $('.city-step-details');

  if (!$national.length || typeof IMAOCityRep === 'undefined') {
    return;
  }

  $('.crm-select2').select2({ width: 'resolve' });

  const toggleFormState = (enabled) => {
    $citySelect.prop('disabled', !enabled).trigger('change.select2');
    $genderSelect.prop('disabled', !enabled).trigger('change.select2');
    $submitBtn.prop('disabled', !enabled);
    $detailFields.toggleClass('is-visible', enabled).toggleClass('is-hidden', !enabled);
  };

  toggleFormState(!!$userId.val());

  $national.on('input', function () {
    $name.val('');
    $userId.val('');
    toggleFormState(false);
  });

  const showSwal = (options) => {
    if (typeof Swal === 'undefined') {
      if (options && options.text) {
        alert(options.text);
      }
      return null;
    }
    return Swal.fire(options);
  };

  const loadingSwal = () => showSwal({
    title: 'در حال بررسی کاربر',
    html: 'لطفاً کمی صبر کنید...',
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });

  $checkBtn.on('click', function (e) {
    e.preventDefault();
    const nid = ($national.val() || '').trim();
    if (!nid) {
      showSwal({ icon: 'warning', title: 'خطا', text: 'کد ملی را وارد کنید.' });
      return;
    }
    loadingSwal();
    $.post(IMAOCityRep.ajax, {
      action: 'imao_lookup_user_national',
      nonce: IMAOCityRep.nonce,
      national_id: nid
    }).done(function (res) {
      if (typeof Swal !== 'undefined') {
        Swal.close();
      }
      if (res && res.success) {
        $name.val(res.data.name || '');
        $userId.val(res.data.user_id || '');
        toggleFormState(true);
        showSwal({
          icon: 'success',
          title: 'کاربر یافت شد',
          text: 'حال می‌توانید شهرستان و جنسیت را انتخاب کنید.',
          timer: 1800,
          showConfirmButton: false
        });
      } else {
        $name.val('');
        $userId.val('');
        toggleFormState(false);
        showSwal({
          icon: 'error',
          title: 'کاربر یافت نشد',
          text: res && res.data && res.data.message ? res.data.message : 'کاربر با این کد ملی یافت نشد.'
        });
      }
    }).fail(function (xhr) {
      $name.val('');
      $userId.val('');
      toggleFormState(false);
      const message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message
        ? xhr.responseJSON.data.message
        : 'خطا در برقراری ارتباط. دوباره تلاش کنید.';
      showSwal({
        icon: 'error',
        title: 'خطا',
        text: message
      });
    });
  });
});
