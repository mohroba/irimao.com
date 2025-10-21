(function ($) {
  'use strict';

  const settings = window.crmAssignPoints || {};
  const actions = settings.actions || {};
  const i18n = settings.i18n || {};
  const ajaxUrl = settings.ajax_url || '';
  const nonce = settings.nonce || '';

  const $competition = $('select[name="competition_id"]');
  const $weight = $('select[name="weight_class"]');
  const $user = $('select[name="user_id"]');
  const $showAll = $('#crm-show-all-attendees');

  let pendingWeight = String($weight.data('selected') || '');
  let pendingUser = String($user.data('selected') || '');

  const weightPlaceholder = $weight.data('placeholder') || i18n.weight_placeholder || '';
  const userPlaceholder = $user.data('placeholder') || i18n.user_placeholder || '';
  const loadingText = i18n.loading || '...';
  const needCompetitionText = i18n.select_competition || '';
  const needWeightText = i18n.select_weight || '';
  const noWeightsText = i18n.no_weights || '';
  const noAttendeesText = i18n.no_attendees || '';
  const errorText = i18n.error_generic || 'Error';

  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function showPlaceholder($select, text, disable) {
    const label = escapeHtml(text || '');
    $select.html('<option value="">' + label + '</option>');
    $select.prop('disabled', !!disable).val('').trigger('change.select2');
  }

  function setOptions($select, placeholder, options, selected) {
    const items = [];
    if (placeholder) {
      items.push('<option value="">' + escapeHtml(placeholder) + '</option>');
    }
    (options || []).forEach(function (opt) {
      const value = String(opt && opt.id !== undefined ? opt.id : '');
      const label = escapeHtml(opt && opt.label !== undefined ? opt.label : '');
      const shouldSelect = selected !== undefined && selected !== null && String(selected) === value;
      const selectedAttr = shouldSelect ? ' selected' : '';
      items.push('<option value="' + escapeHtml(value) + '"' + selectedAttr + '>' + label + '</option>');
    });
    $select.html(items.join(''));
    const targetValue = selected !== undefined && selected !== null ? String(selected) : '';
    $select.val(targetValue);
    $select.prop('disabled', false).trigger('change.select2');
  }

  function getCompetitionId() {
    return parseInt($competition.val(), 10) || 0;
  }

  function getWeightId() {
    return parseInt($weight.val(), 10) || 0;
  }

  function fetchWeights(selectedWeight) {
    const competitionId = getCompetitionId();
    pendingUser = '';
    if (!competitionId) {
      showPlaceholder($weight, needCompetitionText || weightPlaceholder, true);
      showPlaceholder($user, needWeightText || userPlaceholder, true);
      return;
    }
    showPlaceholder($weight, loadingText, true);
    $.ajax({
      url: ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: actions.weights,
        nonce: nonce,
        competition_id: competitionId
      }
    }).done(function (res) {
      if (res && res.success && res.data && Array.isArray(res.data.weights)) {
        const chosen = selectedWeight || pendingWeight;
        setOptions($weight, weightPlaceholder, res.data.weights, chosen && String(chosen));
        pendingWeight = '';
        if ($showAll.prop('checked') || getWeightId()) {
          fetchAttendees(chosen && parseInt(chosen, 10));
        } else {
          showPlaceholder($user, needWeightText || userPlaceholder, true);
        }
      } else {
        showPlaceholder($weight, noWeightsText || weightPlaceholder, true);
        showPlaceholder($user, needWeightText || userPlaceholder, true);
      }
    }).fail(function () {
      showPlaceholder($weight, errorText, true);
      showPlaceholder($user, errorText, true);
    });
  }

  function fetchAttendees(forcedWeight) {
    const competitionId = getCompetitionId();
    const includeAll = $showAll.prop('checked');
    const weightId = forcedWeight !== undefined && forcedWeight !== null ? parseInt(forcedWeight, 10) || 0 : getWeightId();

    if (!competitionId) {
      showPlaceholder($user, needCompetitionText || userPlaceholder, true);
      return;
    }
    if (!includeAll && !weightId) {
      showPlaceholder($user, needWeightText || userPlaceholder, true);
      return;
    }

    showPlaceholder($user, loadingText, true);

    $.ajax({
      url: ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: actions.attendees,
        nonce: nonce,
        competition_id: competitionId,
        weight_class: weightId,
        include_all: includeAll ? 1 : 0
      }
    }).done(function (res) {
      if (res && res.success && res.data && Array.isArray(res.data.attendees)) {
        const selected = pendingUser || $user.val();
        setOptions($user, userPlaceholder, res.data.attendees, selected);
        pendingUser = '';
      } else {
        showPlaceholder($user, noAttendeesText || userPlaceholder, true);
      }
    }).fail(function () {
      showPlaceholder($user, errorText, true);
    });
  }

  $competition.on('change', function () {
    pendingWeight = '';
    fetchWeights();
  });

  $weight.on('change', function () {
    pendingUser = '';
    fetchAttendees();
  });

  $showAll.on('change', function () {
    fetchAttendees();
  });

  if (getCompetitionId()) {
    fetchWeights(pendingWeight);
  } else {
    showPlaceholder($weight, needCompetitionText || weightPlaceholder, true);
    showPlaceholder($user, needCompetitionText || userPlaceholder, true);
  }
})(jQuery);
