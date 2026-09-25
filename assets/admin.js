(function () {
    'use strict';

    function initToast() {
        const toast = document.querySelector('.tgb-admin .tgb-toast');
        if (!toast) {
            return;
        }

        let timer;
        function dismiss() {
            window.clearTimeout(timer);
            toast.classList.add('is-leaving');
            window.setTimeout(function () {
                toast.remove();
            }, 220);
        }
        function scheduleDismiss() {
            window.clearTimeout(timer);
            timer = window.setTimeout(dismiss, 6000);
        }

        toast.querySelector('.tgb-toast-close').addEventListener('click', dismiss);
        toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
        toast.addEventListener('mouseleave', scheduleDismiss);
        toast.addEventListener('focusin', function () { window.clearTimeout(timer); });
        toast.addEventListener('focusout', scheduleDismiss);
        scheduleDismiss();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initToast);
    } else {
        initToast();
    }
})();
