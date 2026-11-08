(function () {
    'use strict';
    function format(input) {
        var value = input.value.replace(/[۰-۹]/g, function (digit) { return String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)); })
            .replace(/[٠-٩]/g, function (digit) { return String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)); }).replace(/[^0-9]/g, '');
        input.value = value.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }
    document.querySelectorAll('[data-imao-money]').forEach(format);
    document.addEventListener('input', function (event) {
        if (event.target.matches('[data-imao-money]')) format(event.target);
    });
})();
