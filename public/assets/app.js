/**
 * رفتارهای تعاملیِ پنل مدیریت (بدون وابستگی خارجی).
 */
(function () {
    'use strict';

    // تأیید برای عملیات خطرناک
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-confirm]');
        if (!el) return;
        if (!window.confirm(el.getAttribute('data-confirm'))) {
            event.preventDefault();
        }
    });

    // کپی در کلیپ‌بورد
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-copy]');
        if (!el) return;
        event.preventDefault();
        var target = document.querySelector(el.getAttribute('data-copy'));
        var text = target ? (target.value || target.textContent) : el.getAttribute('data-copy-value') || '';
        var button = el;
        var original = button.textContent;

        var done = function () {
            button.textContent = 'کپی شد ✓';
            window.setTimeout(function () { button.textContent = original; }, 1600);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text.trim()).then(done, fallback);
        } else {
            fallback();
        }

        function fallback() {
            if (!target) return;
            try {
                target.select ? target.select() : null;
                document.execCommand('copy');
                done();
            } catch (e) { /* ignore */ }
        }
    });

    // ارسال خودکار فرم هنگام تغییر فیلترهای انتخابی
    document.addEventListener('change', function (event) {
        var el = event.target.closest('[data-auto-submit]');
        if (!el) return;
        var form = el.closest('form');
        if (form) form.submit();
    });
})();
