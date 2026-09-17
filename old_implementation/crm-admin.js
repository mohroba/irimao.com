/* --- crm-admin.js --- */
jQuery(function ($) {

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
  $('#crm-prof-table').DataTable({
    pageLength: 15,
    responsive: true,
    language: {
      search: "جستجو:",
      lengthMenu: "نمایش _MENU_ ردیف",
      info: "صفحه _PAGE_ از _PAGES_",
      paginate: { next: "بعدی", previous: "قبلی" },
      zeroRecords: "داده‌ای یافت نشد"
    }
  });

	/* Select2 role selector */
	$('.role-select').select2().on('change', function (){
		const $sel = $(this);
		$.post(CRM_ADMIN.ajax, {
			action:'crm_admin_update_role',
			nonce: CRM_ADMIN.nonce,
			user : $sel.data('user-id'),
			role : $sel.val()
		}, res=>{
			alert(res.success ? 'نقش ذخیره شد' : 'خطا');
		});
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
	}, ()=> location.reload() );
});

/* approve / pending buttons (within form) */
$(document).on('click', '.identity-action-form button[name="crm_user_action"]', function (e){
	e.preventDefault();
	const $btn = $(this);
	const uid  = $btn.closest('form').find('input[name="user_id"]').val();
	const act  = $btn.val();
	$.post(CRM_ADMIN.ajax, {
		action:'crm_admin_id_status',
		nonce : CRM_ADMIN.nonce,
		user  : uid,
		action_type: act
	}, ()=> location.reload() );
});

});
