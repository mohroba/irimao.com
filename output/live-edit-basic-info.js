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
  const coachPlaceholder = $coach.find('option[value=""]').first().text() || '— انتخاب مربی —';

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

  function filterCoaches(){
    if (!$coach.length) { return; }
    const gender = $('#gender').val();
    const current = $coach.val();
    $coach.empty();
    $coach.append(new Option(coachPlaceholder, ''));
    Object.entries(typeof CBIF_COACHES !== 'undefined' ? CBIF_COACHES : {}).forEach(([id, coach]) => {
      if (!gender || coach.gender === gender) {
        const selected = current && current === String(id);
        $coach.append(new Option(coach.name, id, selected, selected));
      }
    });
    if (current && !$coach.find('option[value="' + current.replace(/"/g, '\\"') + '"]').length) {
      $coach.val('');
      fillClubs('');
    }
    if($coach.data('select2')){
      $coach.trigger('change.select2');
    }
  }

  filterCoaches();
  fillClubs($coach.val() || initialCoach);
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
    $('#gender').on('change', function(){
      toggleMilitary();
      filterCoaches();
      fillClubs($coach.val());
    });
    toggleMilitary(); // run on load

    const sdData = (typeof IMAOSD !== 'undefined') ? IMAOSD : {};
    const degreeOptions = sdData.degreeOptions || {};
    const championAgeMap = sdData.championAgeMap || {};
    const $type   = $('#coursetype');
    const $degree = $('#degree');
    const $ageField = $('#age_category_field');
    const $weightField = $('#weight_class_field');
    const $styleField = $('#competition_style_field');
    const $ageSelect = $('#age_category');
    const $weightSelect = $('#weight_class');
    const $competitionStyle = $('#competition_style');

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
                const opt = new Option(label, val);
                if(preselected && String(preselected) === String(val)){
                    opt.selected = true;
                }
                $degree.append(opt);
            });
            if(preselected && list[preselected]){
                $degree.val(preselected);
            }
        }
        if($.fn.select2){
            $degree.trigger('change.select2');
        }
    }

    function fillAgeCategories(){
        if(!$ageSelect.length){ return ''; }
        const datasetVal = $ageSelect.data('selected');
        const currentVal = $ageSelect.val();
        const preselected = datasetVal ? String(datasetVal) : (currentVal ? String(currentVal) : '');
        $ageSelect.empty();
        $ageSelect.append(new Option('— انتخاب کنید —',''));
        Object.entries(championAgeMap).forEach(([id, meta]) => {
            const label = (meta && typeof meta === 'object' && meta.label) ? meta.label : meta;
            const option = new Option(label, id);
            if(preselected && String(preselected) === String(id)){
                option.selected = true;
            }
            $ageSelect.append(option);
        });
        const finalVal = $ageSelect.val();
        $ageSelect.data('selected','');
        if($.fn.select2){
            $ageSelect.trigger('change.select2');
        }
        return finalVal || '';
    }

    function fillWeightClasses(ageId){
        if(!$weightSelect.length){ return; }
        const mapEntry = (ageId && championAgeMap[ageId]) ? championAgeMap[ageId] : null;
        const weights = mapEntry && typeof mapEntry === 'object' && mapEntry.weights ? mapEntry.weights : {};
        const datasetVal = $weightSelect.data('selected');
        const currentVal = $weightSelect.val();
        const preselected = datasetVal ? String(datasetVal) : (currentVal ? String(currentVal) : '');
        $weightSelect.empty();
        if(!ageId || Object.keys(weights).length === 0){
            $weightSelect.append(new Option('— ابتدا رده سنی را انتخاب کنید —',''));
        }else{
            $weightSelect.append(new Option('— انتخاب کنید —',''));
            Object.entries(weights).forEach(([id, label]) => {
                const option = new Option(label, id);
                if(preselected && String(preselected) === String(id)){
                    option.selected = true;
                }
                $weightSelect.append(option);
            });
        }
        if(preselected && weights && Object.prototype.hasOwnProperty.call(weights, preselected)){
            $weightSelect.val(preselected);
        }
        $weightSelect.data('selected','');
        if($.fn.select2){
            $weightSelect.trigger('change.select2');
        }
    }

    function toggleChampionFields(){
        if(!$type.length){ return; }
        const isChampion = String($type.val()) === '4';
        [$ageField, $weightField, $styleField].forEach($field => {
            if($field && $field.length){
                $field.toggle(isChampion);
            }
        });
        if($ageSelect.length){
            $ageSelect.prop('required', isChampion);
        }
        if($weightSelect.length){
            $weightSelect.prop('required', isChampion);
        }
        if($competitionStyle.length){
            $competitionStyle.prop('required', isChampion);
            if(!isChampion){
                $competitionStyle.val('');
            }
        }
        if(isChampion){
            const selectedAge = fillAgeCategories();
            const ageId = selectedAge || $ageSelect.val();
            fillWeightClasses(ageId);
        }else{
            if($ageSelect.length){
                $ageSelect.val('');
                $ageSelect.data('selected','');
                if($.fn.select2){
                    $ageSelect.trigger('change.select2');
                }
            }
            if($weightSelect.length){
                $weightSelect.val('');
                $weightSelect.data('selected','');
                $weightSelect.empty().append(new Option('— ابتدا رده سنی را انتخاب کنید —',''));
                if($.fn.select2){
                    $weightSelect.trigger('change.select2');
                }
            }
        }
    }

    if($ageSelect.length){
        $ageSelect.on('change', function(){
            fillWeightClasses(this.value);
        });
    }

    function handleTypeChange(){
        fillDegrees();
        toggleChampionFields();
    }

    $type.on('change', handleTypeChange);
    handleTypeChange();

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
