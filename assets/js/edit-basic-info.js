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

  $('.crm-select2').select2({ dir: 'rtl', width: 'resolve' });

  const $coach = $('#coach_id');
  const $club  = $('#club_id');
  const initialCoach = $coach.val();
  const initialClub  = $club.val();

  function clubTpl(state){
    if(!state.id){ return state.text; }
    const addr = $(state.element).data('address');
    if(addr){
      return $('<span>'+state.text+'<small class="club-addr">'+addr+'</small></span>');
    }
    return state.text;
  }

  function fillClubs(coachId){
    const list = (typeof CBIF_CLUBS !== 'undefined' && CBIF_CLUBS[coachId]) ? CBIF_CLUBS[coachId] : [];
    const current = $club.val();
    $club.empty();
    $club.append(new Option('— انتخاب باشگاه —',''));
    list.forEach(c => {
      const selected = current && current === String(c.id);
      const opt = new Option(c.name, c.id, selected, selected);
      opt.dataset.address = c.address;
      $club.append(opt);
    });
    if($club.data('select2')){
      $club.trigger('change.select2');
    }
  }

  fillClubs(initialCoach);
  if($club.data('select2')){ $club.select2('destroy'); }
  $club.select2({ dir: 'rtl', width: 'resolve', templateResult: clubTpl, templateSelection: clubTpl });
  $coach.on('change', function(){ fillClubs(this.value); });

  function toggleMilitary(){
      var g = $('#gender').val();
      var $ms = $('#military_status');
      var $wrap = $ms.closest('.cbif-field');
      if(g === 'female'){
          $ms.prop('disabled', true);
          $wrap.hide();
      }else{
          $ms.prop('disabled', false);
          $wrap.show();
      }
    }
    $('#gender').on('change', toggleMilitary);
    toggleMilitary(); // run on load

    const degreeOptions = (typeof IMAOSD !== 'undefined') ? IMAOSD.degreeOptions : {};
    const $type   = $('#coursetype');
    const $degree = $('#degree');
    function fillDegrees(){
        if(!$type.length || !$degree.length){return;}
        const list = degreeOptions[$type.val()] || {};
        const preselected = $degree.data('selected');
        $degree.empty();
        if(Object.keys(list).length === 0){
            $('#degree_field').hide();
            $degree.prop('required', false);
            $degree.append(new Option('— نوع حکم را انتخاب کنید —',''));
        }else{
            $('#degree_field').show();
            $degree.prop('required', true);
            $degree.append(new Option('— انتخاب کنید —',''));
            Object.entries(list).forEach(([val,label]) => {
                $degree.append(new Option(label, val));
            });
            if(preselected && list[preselected]){
                $degree.val(preselected);
            }
        }
        if($.fn.select2){
            $degree.trigger('change.select2');
        }
    }
    $type.on('change', fillDegrees);
    fillDegrees();

    const $form  = $('#id-form');
    const status = $form.data('status');
    if (status && status !== 'pending' && status !== 'rejected') {
        $form
            .find(
                'input:not([name="coach_id"]):not([name="club_id"]):not([type="hidden"]), ' +
                'select:not([name="coach_id"]):not([name="club_id"]), textarea:not([name="coach_id"]):not([name="club_id"])'
            )
            .prop('disabled', true);

        $form.on('submit', function(e){
            const coachChanged = $coach.val() !== initialCoach;
            const clubChanged  = $club.val() !== initialClub;
            if (!coachChanged && !clubChanged) {
                e.preventDefault();
                e.stopImmediatePropagation();
                Swal.fire({
                    icon: 'warning',
                    text: 'اطلاعات پایه شما تایید شده و قابل ویرایش نیست.',
                });
            }
        });
    }
});
