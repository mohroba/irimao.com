(function () {
    'use strict';
    var box = document.getElementById('imao_competition_cards');
    if (!box) return;
    var dirty = false;
    box.addEventListener('change', function (event) {
        if (event.target.name && event.target.name.indexOf('imao_weigh_ins[') === 0) dirty = true;
    });
    function canNavigate() {
        return !dirty || window.confirm('تغییرات وزن‌کشی ذخیره نشده است. برای ذخیره، ابتدا مسابقه را به‌روزرسانی کنید. بدون ذخیره ادامه می‌دهید؟');
    }
    box.querySelectorAll('.imao-weigh-pagination a').forEach(function (link) {
        link.addEventListener('click', function (event) { if (!canNavigate()) event.preventDefault(); });
    });
    var search = document.getElementById('imao-weigh-in-search');
    function submitSearch() {
        if (!canNavigate()) return;
        var url = new URL(window.location.href);
        url.searchParams.set('imao_weigh_search', search.value.trim());
        url.searchParams.set('imao_weigh_page', '1');
        url.hash = 'imao_competition_cards';
        window.location.assign(url.href);
    }
    document.getElementById('imao-weigh-search-button').addEventListener('click', submitSearch);
    search.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') { event.preventDefault(); submitSearch(); }
    });
}());
