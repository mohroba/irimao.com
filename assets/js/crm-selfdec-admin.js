jQuery(function($){
  // initialize DataTable
  $('#crm-selfdec-table').DataTable({
    pageLength: 15,
    responsive:  true,
    language: {
      search:      "جستجو:",
      lengthMenu:  "نمایش _MENU_ ردیف",
      info:        "نمایش _START_ تا _END_ از _TOTAL_",
      paginate:    { previous: "قبلی", next: "بعدی" },
      zeroRecords: "داده‌ای یافت نشد"
    }
  });

  // Approve button
  $('.approve-btn').on('click', function(){
    const row    = $(this).closest('tr'),
          pid    = row.data('id');

    $.post( CRM_SELFDEC.ajax_url, {
    action   : 'crm_selfdec_change_status', 
    nonce    : CRM_SELFDEC.nonce,
    post_id  : pid,
    decision : 'approve'
}, function(){
      location.reload();
    });
  });

  // Disapprove button with prompt for reason
  $('.disapprove-btn').on('click', function(){
    const row    = $(this).closest('tr'),
          pid    = row.data('id'),
          reason = prompt('لطفاً دلیل رد را وارد کنید:');

    if ( reason ) {
      $.post( CRM_SELFDEC.ajax_url, {
        action   : 'crm_selfdec_change_status',
        nonce    : CRM_SELFDEC.nonce,
        post_id  : pid,
        decision : 'disapprove',
        reason   : reason
    }, function(){
        location.reload();
      });
    }
  });

  // Delete button
  $('.delete-btn').on('click', function(){
    const row = $(this).closest('tr'),
          pid = row.data('id');
    if (!confirm('آیا از حذف این رکورد اطمینان دارید؟')) return;
    $.post(CRM_SELFDEC.ajax_url, {
      action  : 'crm_selfdec_delete',
      nonce   : CRM_SELFDEC.nonce,
      post_id : pid
    }, function(){
      location.reload();
    });
  });
});
