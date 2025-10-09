(function ($) {
    'use strict';

    var CONFIRM_TEXT = 'آیا از خرید اشتراک خود اطمینان دارید؟';
    var TARGET_PHRASE = 'خرید اشتراک';

    function isVipPath(pathname) {
        if (typeof pathname !== 'string') {
            return false;
        }
        var normalized = pathname.replace(/\/+$/, '');
        return normalized === '/profile/vip';
    }

    function normalizeLabel(text) {
        return (text || '').replace(/\s+/g, ' ').trim();
    }

    function needsConfirmation($element) {
        if (!$element || !$element.length) {
            return false;
        }
        var label = '';
        if ($element.is('input')) {
            label = $element.val();
        } else {
            label = $element.text();
        }
        label = normalizeLabel(label);
        return label.indexOf(TARGET_PHRASE) !== -1;
    }

    function handleEvent(event, $element) {
        if (!needsConfirmation($element)) {
            return;
        }
        if (!window.confirm(CONFIRM_TEXT)) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }

    $(function () {
        if (!isVipPath(window.location.pathname)) {
            return;
        }

        $(document).on('click', 'a, button, input[type="submit"]', function (event) {
            handleEvent(event, $(this));
        });

        $(document).on('submit', 'form', function (event) {
            var $form = $(this);
            var $submitElements = $form.find('button[type="submit"], input[type="submit"]').filter(function () {
                return needsConfirmation($(this));
            });
            if ($submitElements.length > 0) {
                handleEvent(event, $submitElements.first());
            }
        });
    });
})(jQuery);
