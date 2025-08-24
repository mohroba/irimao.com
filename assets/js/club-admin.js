jQuery(function($){
  $('body').on('click','.view-club',function(){
    var pid=$(this).data('pid');
    var modalEl=document.getElementById('club-modal');
    var modal=new bootstrap.Modal(modalEl);
    $('#club-modal .modal-body').html('در حال بارگیری ...');
    modal.show();
    $.post(CLUB_ADMIN.ajax,{action:'crm_club_get',nonce:CLUB_ADMIN.nonce_get,post:pid},function(r){
      if(r.success){
        var d=r.data,html='<h2>'+d.title+'</h2>'+
          '<p><strong>صاحب امتیاز:</strong> '+d.owner+'</p>'+
          '<p><strong>استان/شهر:</strong> '+d.province+' - '+d.city+'</p>'+
          '<p><strong>کد پستی:</strong> '+d.postal+'</p>'+
          '<p><strong>آدرس:</strong><br>'+d.address+'</p>'+
          '<p><strong>تصویر مجوز:</strong><br><a href="'+d.lic+'" target="_blank"><img src="'+d.lic+'" style="max-width:200px;border:1px solid #ccc"></a></p>'+
          (d.reason ? '<p class="text-danger"><strong>دلیل رد:</strong> '+d.reason+'</p>' : '');
        $('#club-modal .modal-body').html(html);
      }else{
        $('#club-modal .modal-body').html('<p class="text-danger">خطا در دریافت اطلاعات.</p>');
      }
    });
  });

  $('#club-table').on('click','.approve-club, .reject-club',function(){
    var $tr=$(this).closest('tr'),dec=$(this).hasClass('approve-club')?'approve':'reject',reason='';
    if(dec==='reject'){
      reason=prompt('دلیل رد این باشگاه؟');
      if(reason===null) return;
    }
    $.post(CLUB_ADMIN.ajax,{
      action:'crm_club_decide',nonce:CLUB_ADMIN.nonce_decide,post:$tr.data('id'),user:$tr.data('user'),decision:dec,reason:reason
    },function(r){
      if(r.success) location.reload();
      else alert('خطا در انجام عملیات');
    });
  });
});
