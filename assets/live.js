(function () {
    'use strict';

    const i18n = window.wp && window.wp.i18n;
    if (!i18n || !window.utgLive) {
        return;
    }

    function unit(count, singular, plural) {
        return i18n._n(singular, plural, count, 'uplink-time-greeting').replace('%d', String(count));
    }

    function duration(milliseconds) {
        const minutes = Math.max(1, Math.ceil(milliseconds / 60000));
        const days = Math.floor(minutes / 1440);
        const hours = Math.floor((minutes % 1440) / 60);
        const remainder = minutes % 60;
        const parts = [];
        if (days) {
            parts.push(unit(days, '%d day', '%d days'));
        }
        if (hours) {
            parts.push(unit(hours, '%d hour', '%d hours'));
        }
        if (remainder && !days) {
            parts.push(unit(remainder, '%d minute', '%d minutes'));
        }
        return parts.join(' ');
    }

    async function refresh(root) {
        if (root.dataset.utgRefreshing === '1') {
            return;
        }
        root.dataset.utgRefreshing = '1';
        try {
            const url = new URL(window.utgLive.restUrl);
            url.searchParams.set('display', root.dataset.utgDisplay || 'greeting');
            url.searchParams.set('date_format', root.dataset.utgDateFormat || 'F j, Y');
            url.searchParams.set('timezone', root.dataset.utgTimezone || '');
            url.searchParams.set('tz_abbr', root.dataset.utgTzAbbr || '');
            url.searchParams.set('_utg', String(Date.now()));
            const response = await fetch(url.toString(), { cache: 'no-store', credentials: 'same-origin' });
            if (!response.ok) {
                throw new Error('Could not refresh greeting');
            }
            const data = await response.json();
            if (!data || typeof data.html !== 'string') {
                throw new Error('Invalid greeting response');
            }
            const template = document.createElement('template');
            template.innerHTML = data.html;
            const replacement = template.content.firstElementChild;
            if (replacement && replacement.classList.contains('utg-output')) {
                root.replaceWith(replacement);
            }
        } catch (error) {
            root.dataset.utgRefreshing = '0';
        }
    }

    function tick() {
        const now = Date.now();
        document.querySelectorAll('.utg-output[data-utg-transition]').forEach(function (root) {
            const transition = Number(root.dataset.utgTransition);
            if (transition && now >= transition) {
                refresh(root);
                return;
            }
            root.querySelectorAll('.utg-countdown[data-utg-target]').forEach(function (countdown) {
                const target = Number(countdown.dataset.utgTarget);
                if (target && target > now) {
                    countdown.textContent = duration(target - now);
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tick);
    } else {
        tick();
    }
    window.setInterval(tick, 15000);
})();
