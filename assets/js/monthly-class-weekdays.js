(function () {
    'use strict';
    document.querySelectorAll('[data-imao-weekdays]').forEach(function (group) {
        var boxes = Array.from(group.querySelectorAll('input[type="checkbox"]'));
        if (!boxes.length) return;
        function validate() {
            boxes[0].setCustomValidity(boxes.some(function (box) { return box.checked; })
                ? '' : 'حداقل یک روز هفته را انتخاب کنید.');
        }
        group.addEventListener('change', validate);
        if (group.closest('form')) {
            group.closest('form').addEventListener('reset', function () { setTimeout(validate, 0); });
        }
        validate();
    });
})();
