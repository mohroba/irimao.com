document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.custom-file-wrapper').forEach(function (wrapper) {
        var input = wrapper.querySelector('input[type="file"]');
        var btn = wrapper.querySelector('.custom-file-btn');
        var nameEl = wrapper.parentElement.querySelector('.file-name');
        var previewImg = wrapper.closest('.upload-item').querySelector('.upload-thumbnail img');
        var previewLink = previewImg.closest('a');
        var originalSrc = previewImg.getAttribute('src');
        var originalHref = previewLink ? previewLink.getAttribute('href') : null;
        var noFile = wrapper.dataset.nofile || 'فایلی انتخاب نشده';

        btn.addEventListener('click', function () { input.click(); });
        input.addEventListener('change', function () {
            var file = input.files[0];
            if (!file) {
                nameEl.textContent = noFile;
                previewImg.setAttribute('src', originalSrc);
                if (previewLink && originalHref) {
                    previewLink.setAttribute('href', originalHref);
                    previewLink.setAttribute('target', '_blank');
                }
                return;
            }
            if (!file.type.startsWith('image/')) {
                Swal.fire({ icon: 'error', text: 'فقط فایل‌های تصویری مجاز هستند.' });
                input.value = '';
                nameEl.textContent = noFile;
                previewImg.setAttribute('src', originalSrc);
                if (previewLink && originalHref) {
                    previewLink.setAttribute('href', originalHref);
                    previewLink.setAttribute('target', '_blank');
                }
                return;
            }
            nameEl.textContent = file.name;
            var url = URL.createObjectURL(file);
            previewImg.setAttribute('src', url);
            if (previewLink) {
                previewLink.removeAttribute('href');
                previewLink.removeAttribute('target');
            }
        });
    });

    var form = document.querySelector('.crm-identity-verification-form form');
    if (form) {
        var status = form.dataset.status;
        if (status && status !== 'pending' && status !== 'disapproved') {
            form.querySelectorAll('input[type="file"]').forEach(function(inp){ inp.disabled = true; });
            form.querySelectorAll('button[type="submit"]').forEach(function(btn){ btn.disabled = true; });
            form.querySelectorAll('.custom-file-btn').forEach(function(btn){ btn.disabled = true; });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                Swal.fire({
                    icon: 'warning',
                    text: 'مدارک شما تایید شده و قابل ویرایش نیست.',
                });
            });
        }
    }
});
