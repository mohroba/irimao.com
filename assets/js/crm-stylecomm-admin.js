jQuery(function($){
  $('#crm-stylecomm-table').DataTable({
    pageLength: 15,
    responsive: true,
    language: {
      search: 'جستجو:',
      lengthMenu: 'نمایش _MENU_ ردیف',
      info: 'نمایش _START_ تا _END_ از _TOTAL_',
      paginate: { previous: 'قبلی', next: 'بعدی' },
      zeroRecords: 'داده‌ای یافت نشد'
    }
  });

  $('.approve-btn').on('click', function(){
    const row = $(this).closest('tr'), pid = row.data('id');
    $.post(CRM_STYLECOMM.ajax_url, {
      action: 'crm_stylecomm_change_status',
      nonce: CRM_STYLECOMM.nonce,
      post_id: pid,
      decision: 'approve'
    }, function(){ location.reload(); });
  });

  $('.disapprove-btn').on('click', function(){
    const row = $(this).closest('tr'), pid = row.data('id'), reason = prompt('لطفاً دلیل رد را وارد کنید:');
    if (!reason) return;
    $.post(CRM_STYLECOMM.ajax_url, {
      action: 'crm_stylecomm_change_status',
      nonce: CRM_STYLECOMM.nonce,
      post_id: pid,
      decision: 'disapprove',
      reason: reason
    }, function(){ location.reload(); });
  });

  $('.delete-btn').on('click', function(){
    const row = $(this).closest('tr'), pid = row.data('id');
    if (!confirm('آیا از حذف این رکورد اطمینان دارید؟')) return;
    $.post(CRM_STYLECOMM.ajax_url, {
      action: 'crm_stylecomm_delete',
      nonce: CRM_STYLECOMM.nonce,
      post_id: pid
    }, function(){ location.reload(); });
  });
});
