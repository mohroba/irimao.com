document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.custom-file-wrapper').forEach(function (wrapper) {
        var input = wrapper.querySelector('input[type="file"]');
        var btn = wrapper.querySelector('.custom-file-btn');
        var nameEl = wrapper.querySelector('.file-name');
        var noFile = wrapper.dataset.nofile || 'فایلی انتخاب نشده';
        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            nameEl.textContent = input.files.length ? input.files[0].name : noFile;
        });
    });
});
