jQuery(function ($) {
  const $national = $('#city_national_id');
  const $name = $('#city_user_name');
  const $userId = $('#city_user_id');
  const $checkBtn = $('#city_national_check');

  if (!$national.length || typeof IMAOCityRep === 'undefined') {
    return;
  }

  $('.crm-select2').select2({ width: 'resolve' });

  $checkBtn.on('click', function (e) {
    e.preventDefault();
    const nid = ($national.val() || '').trim();
    if (!nid) {
      alert('کد ملی را وارد کنید.');
      return;
    }
    $.post(IMAOCityRep.ajax, {
      action: 'imao_lookup_user_national',
      nonce: IMAOCityRep.nonce,
      national_id: nid
    }).done(function (res) {
      if (res && res.success) {
        $name.val(res.data.name || '');
        $userId.val(res.data.user_id || '');
      } else {
        $name.val('');
        $userId.val('');
        alert(res && res.data && res.data.message ? res.data.message : 'کاربر با این کد ملی یافت نشد.');
      }
    }).fail(function () {
      $name.val('');
      $userId.val('');
      alert('خطا در برقراری ارتباط. دوباره تلاش کنید.');
    });
  });
});
