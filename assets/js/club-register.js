jQuery(function($){
  function loadCities(prov, $select){
    const list = (typeof CBIF_CITIES !== 'undefined' && CBIF_CITIES[prov]) ? CBIF_CITIES[prov] : [];
    let opts = '<option value="">— انتخاب کنید —</option>';
    list.forEach(c => { opts += `<option>${c}</option>`; });
    $select.html(opts).prop('disabled', list.length === 0);
  }
  $('#club_province').on('change', function(){
    loadCities(this.value, $('#club_city'));
  });
});
