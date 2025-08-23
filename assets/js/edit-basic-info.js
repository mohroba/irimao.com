jQuery(function($){
  const spinner =
    '<span class="cbif-spinner" style="display:inline-block;margin-left:8px;">' +
    '<svg width="18" height="18" viewBox="0 0 24 24" class="spin">' +
    '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="2" fill="none"/>' +
    '</svg></span>';

  function loadCities(prov, $select){
    $select.prop('disabled',true).html('<option>…در حال بارگذاری…</option>');
    const $loader = $(spinner).insertAfter($select);

    setTimeout(function(){
      const list = CBIF_CITIES[prov] || [];
      let opts = '<option value="">— انتخاب کنید —</option>';
      list.forEach(c=> opts += `<option>${c}</option>`);
      $select.html(opts).prop('disabled',false);
      $loader.remove();
    }, 100);
  }

  $('#birth_province').on('change', function(){
    loadCities(this.value, $('#birth_city'));
  });
  $('#residence_province').on('change', function(){
    loadCities(this.value, $('#residence_city'));
  });
  
   function toggleMilitary(){
      var g = $('#gender').val();
      $('#military_status').prop('disabled', g==='female');
    }
    $('#gender').on('change', toggleMilitary);
    toggleMilitary(); // run on load
    
    $('.crm-select2').select2({ dir: 'rtl', width: 'resolve' });
    jalaliDatepicker.startWatch();
});
