jQuery(function($){
  const table = $('#crm-selfdec-table').DataTable({
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

  function showError(response){
    const message = response && response.responseJSON && response.responseJSON.data && response.responseJSON.data.message
      ? response.responseJSON.data.message
      : 'عملیات انجام نشد. لطفاً دوباره تلاش کنید.';
    alert(message);
  }

  function updateStatus(row, label){
    row.find('td').eq(12).text(label);
    table.row(row).invalidate().draw(false);
  }

  function changeStatus(row, decision, reason){
    const pid = row.data('id');
    row.find('button').prop('disabled', true);
    $.post(CRM_SELFDEC.ajax_url, {
      action: 'crm_selfdec_change_status',
      nonce: CRM_SELFDEC.nonce,
      post_id: pid,
      decision: decision,
      reason: reason || ''
    }).done(function(response){
      if (response && response.success) {
        updateStatus(row, response.data && response.data.status_label ? response.data.status_label : '—');
      } else {
        showError({responseJSON: response});
      }
    }).fail(showError).always(function(){
      row.find('button').prop('disabled', false);
    });
  }

  $('#crm-selfdec-table').on('click', '.approve-btn', function(){
    changeStatus($(this).closest('tr'), 'approve');
  });

  // Disapprove button with prompt for reason
  $('#crm-selfdec-table').on('click', '.disapprove-btn', function(){
    const row    = $(this).closest('tr'),
          reason = prompt('لطفاً دلیل رد را وارد کنید:');

    if ( reason ) {
      changeStatus(row, 'disapprove', reason);
    }
  });

  // Delete button
  $('#crm-selfdec-table').on('click', '.delete-btn', function(){
    const row = $(this).closest('tr'),
          pid = row.data('id');
    if (!confirm('آیا از حذف این رکورد اطمینان دارید؟')) return;
    $.post(CRM_SELFDEC.ajax_url, {
      action  : 'crm_selfdec_delete',
      nonce   : CRM_SELFDEC.nonce,
      post_id : pid
    }).done(function(response){
      if (response && response.success) {
        table.row(row).remove().draw(false);
      } else {
        showError({responseJSON: response});
      }
    }).fail(showError);
  });
});
