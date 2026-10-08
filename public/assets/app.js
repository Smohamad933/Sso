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

    // زبانه‌ها (مستندات)
    document.addEventListener('click', function (event) {
        var el = event.target.closest('[data-tab]');
        if (!el) return;
        var group = el.closest('[data-tab-group]');
        if (!group) return;
        event.preventDefault();

        var name = el.getAttribute('data-tab');
        var i;

        var tabs = group.querySelectorAll('[data-tab]');
        for (i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle('is-active', tabs[i] === el);
        }

        var panels = group.querySelectorAll('[data-tab-panel]');
        for (i = 0; i < panels.length; i++) {
            var match = panels[i].getAttribute('data-tab-panel') === name;
            if (match) { panels[i].removeAttribute('hidden'); }
            else { panels[i].setAttribute('hidden', 'hidden'); }
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
