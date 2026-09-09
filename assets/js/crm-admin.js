/* --- crm-admin.js --- */
jQuery(function ($) {

  function showPageNotice(message, type) {
    const noticeType = type === 'error' ? 'notice-error' : 'notice-success';
    $('.imao-admin-ajax-notice').remove();
    $('<div class="notice ' + noticeType + ' is-dismissible imao-admin-ajax-notice"><p></p></div>')
      .find('p').text(message).end()
      .insertAfter('.wrap > h1:first');
  }

  function reloadWithNotice(message) {
    try {
      window.sessionStorage.setItem('imaoAdminNotice', message);
    } catch (error) {
      // Storage may be disabled; the operation can still complete safely.
    }
    window.location.reload();
  }

  function initializeRoleSelects(context) {
    $(context).find('.role-select').each(function () {
      const $select = $(this);
      if ($select.hasClass('select2-hidden-accessible')) return;
      $select.select2({
        dir: 'rtl',
        width: '100%',
        placeholder: $select.data('placeholder') || 'انتخاب نقش‌ها'
      });
    });
  }

  try {
    const savedNotice = window.sessionStorage.getItem('imaoAdminNotice');
    if (savedNotice) {
      window.sessionStorage.removeItem('imaoAdminNotice');
      showPageNotice(savedNotice, 'success');
    }
  } catch (error) {
    // Storage may be disabled.
  }

 /* جدول اطلاعات پایه */
  $('#crm-basic-table').DataTable({
    pageLength: 20,              // تعداد ردیف در هر صفحه
    responsive: true,
    stateSave: true,             // به خاطر سپردن صفحه و فیلتر
    language: {
      search: "جستجو:",
      lengthMenu: "نمایش _MENU_ ردیف",
      info: "صفحه _PAGE_ از _PAGES_",
      infoEmpty: "بدون داده",
      paginate: { next: "بعدی", previous: "قبلی" },
      zeroRecords: "داده‌ای یافت نشد"
    }
  });

  /* جدول هویت حرفه‌ای */
  const professionalTable = $('#crm-prof-table').DataTable({
    pageLength: 15,
    responsive: true,
    language: {
      search: "جستجو:",
      lengthMenu: "نمایش _MENU_ ردیف",
      info: "صفحه _PAGE_ از _PAGES_",
      paginate: { next: "بعدی", previous: "قبلی" },
      zeroRecords: "داده‌ای یافت نشد"
    },
    drawCallback: function () {
      initializeRoleSelects(this.api().table().body());
    }
  });

  initializeRoleSelects(professionalTable.table().body());

  /* Select2 role selector: delegated so controls on every DataTables page work. */
  $(document).on('change', '#crm-prof-table .role-select', function () {
    const $select = $(this);
    const $cell = $select.closest('td');
    let $status = $cell.find('.role-save-status');
    if (!$status.length) {
      $status = $('<span class="role-save-status" role="status" aria-live="polite"></span>').appendTo($cell);
    }

    const previousRequest = $select.data('save-request');
    if (previousRequest && previousRequest.readyState !== 4) previousRequest.abort();

    $select.prop('disabled', true);
    $status.removeClass('is-success is-error').text('در حال ذخیره…');
    const request = $.ajax({
      url: CRM_ADMIN.ajax,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'crm_admin_update_role',
        nonce: CRM_ADMIN.nonce,
        user: $select.data('user-id'),
        roles: $select.val() || []
      }
    }).done(function (response) {
      const message = response && response.data && response.data.msg
        ? response.data.msg
        : (response && response.success ? 'نقش‌ها ذخیره شدند.' : 'ذخیره نقش‌ها انجام نشد.');
      $status.addClass(response && response.success ? 'is-success' : 'is-error').text(message);
    }).fail(function (xhr, textStatus) {
      if (textStatus !== 'abort') $status.addClass('is-error').text('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.');
    }).always(function (_, textStatus) {
      if (textStatus !== 'abort') $select.prop('disabled', false);
    });
    $select.data('save-request', request);
  });

/* disapprove with reason */
$(document).on('click', '.disapprove-btn', function (e){
	e.preventDefault();
	const uid = $(this).data('user');
	const reason = prompt('دلیل رد؟');
	if(!reason) return;

	$.post(CRM_ADMIN.ajax, {
		action:'crm_admin_id_status',
		nonce : CRM_ADMIN.nonce,
		user  : uid,
		action_type:'disapprove',
		reason: reason
	}).done(function (response) {
    if (response && response.success) reloadWithNotice('وضعیت هویت حرفه‌ای ذخیره شد.');
    else showPageNotice('ذخیره وضعیت انجام نشد.', 'error');
  }).fail(function () { showPageNotice('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'error'); });
});

/* approve / pending buttons (within form) */
$(document).on('click', '.identity-action-form button[name="crm_user_action"]', function (e){
        e.preventDefault();
        const $btn = $(this);
        const $row = $btn.closest('tr');
        const uid  = $row.find('input[name="user_id"]').val();
        const act  = $btn.val();
        const roles = $row.find('.role-select').val() || [];
        $.post(CRM_ADMIN.ajax, {
                action:'crm_admin_id_status',
                nonce : CRM_ADMIN.nonce,
                user  : uid,
                action_type: act,
                roles : roles
        }).done(function (response) {
          if (response && response.success) reloadWithNotice('وضعیت هویت حرفه‌ای و نقش‌ها ذخیره شدند.');
          else showPageNotice('ذخیره تغییرات انجام نشد.', 'error');
        }).fail(function () { showPageNotice('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'error'); });
});

/* ban / unban */
$(document).on('click', '.ban-user-btn', function (e){
        e.preventDefault();
        const $btn = $(this);
        $.post(CRM_ADMIN.ajax, {
                action: 'crm_admin_toggle_ban',
                nonce : CRM_ADMIN.nonce,
                user  : $btn.data('user'),
                ban_action: $btn.data('action')
        }).done(function (response) {
          if (response && response.success) reloadWithNotice('وضعیت مسدودی کاربر ذخیره شد.');
          else showPageNotice('ذخیره وضعیت مسدودی انجام نشد.', 'error');
        }).fail(function () { showPageNotice('ارتباط با سرور برقرار نشد؛ دوباره تلاش کنید.', 'error'); });
});

});
