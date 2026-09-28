(function () {
    'use strict';

    const i18n = window.wp && window.wp.i18n;
    if (!i18n || !window.ulhgrLive) {
        return;
    }

    function unit(count, singular, plural) {
        return i18n._n(singular, plural, count, 'uplink-hours-greetings').replace('%d', String(count));
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
        if (root.dataset.ulhgrRefreshing === '1') {
            return;
        }
        root.dataset.ulhgrRefreshing = '1';
        try {
            const url = new URL(window.ulhgrLive.restUrl);
            url.searchParams.set('display', root.dataset.ulhgrDisplay || 'greeting');
            url.searchParams.set('date_format', root.dataset.ulhgrDateFormat || 'F j, Y');
            url.searchParams.set('timezone', root.dataset.ulhgrTimezone || '');
            url.searchParams.set('tz_abbr', root.dataset.ulhgrTzAbbr || '');
            url.searchParams.set('_ulhgr', String(Date.now()));
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
            if (replacement && replacement.classList.contains('ulhgr-output')) {
                root.replaceWith(replacement);
            }
        } catch (error) {
            root.dataset.ulhgrRefreshing = '0';
        }
    }

    function tick() {
        const now = Date.now();
        document.querySelectorAll('.ulhgr-output[data-ulhgr-transition]').forEach(function (root) {
            const transition = Number(root.dataset.ulhgrTransition);
            if (transition && now >= transition) {
                refresh(root);
                return;
            }
            root.querySelectorAll('.ulhgr-countdown[data-ulhgr-target]').forEach(function (countdown) {
                const target = Number(countdown.dataset.ulhgrTarget);
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
