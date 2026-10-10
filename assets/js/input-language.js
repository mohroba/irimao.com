(function () {
    'use strict';
    function normalize(value, language) {
        if (language === 'fa') {
            return value.replace(/ي/g, 'ی').replace(/ك/g, 'ک')
                .replace(/[^\u0621-\u063A\u0641-\u064A\u066E-\u06D3\u06FA-\u06FC\u200C\s'’-]/g, '');
        }
        return value.replace(/[^A-Za-z\s'’-]/g, '');
    }
    if (typeof module !== 'undefined' && module.exports) module.exports = normalize;
    if (typeof document === 'undefined') return;
    var selector = 'input.imao-input-fa,input.imao-input-en,textarea.imao-input-fa,textarea.imao-input-en';
    function enforce(input) {
        if (!input.matches(selector)) return;
        var language = input.classList.contains('imao-input-fa') ? 'fa' : 'en';
        var position = input.selectionStart;
        var original = input.value;
        var value = normalize(original, language);
        input.dir = language === 'fa' ? 'rtl' : 'ltr';
        input.lang = language;
        if (original === value) return;
        input.value = value;
        if (position !== null && typeof input.setSelectionRange === 'function') {
            position = normalize(original.slice(0, position), language).length;
            input.setSelectionRange(position, position);
        }
    }
    // Apply the reusable classes to the existing Persian and English name fields.
    function tagNames(root) {
        root.querySelectorAll('input[name]').forEach(function (input) {
            var match = input.name.match(/(?:first_name|last_name)_(fa|en)(?:\]|$)/);
            if (match) { input.classList.add('imao-input-' + match[1]); input.dir = match[1] === 'fa' ? 'rtl' : 'ltr'; }
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function () { tagNames(document); });
    else tagNames(document);
    document.addEventListener('input', function (event) { if (!event.isComposing) enforce(event.target); });
    document.addEventListener('compositionend', function (event) { enforce(event.target); });
    document.addEventListener('focusin', function (event) {
        var input = event.target;
        if (input.tagName === 'INPUT' && input.name) {
            var match = input.name.match(/(?:first_name|last_name)_(fa|en)(?:\]|$)/);
            if (match) input.classList.add('imao-input-' + match[1]);
        }
    });
}());
