(function () {
    'use strict';

    function initToast() {
        const toast = document.querySelector('.ulhgr-admin .ulhgr-toast');
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

        toast.querySelector('.ulhgr-toast-close').addEventListener('click', dismiss);
        toast.addEventListener('mouseenter', function () { window.clearTimeout(timer); });
        toast.addEventListener('mouseleave', scheduleDismiss);
        toast.addEventListener('focusin', function () { window.clearTimeout(timer); });
        toast.addEventListener('focusout', scheduleDismiss);
        scheduleDismiss();
    }

    function initTabs() {
        const admin = document.querySelector('.ulhgr-admin');
        const tabs = Array.from(document.querySelectorAll('[data-ulhgr-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-ulhgr-panel]'));
        if (!admin || !tabs.length || !panels.length) {
            return;
        }

        function showTab(key, updateHistory, focusTab) {
            const selected = tabs.find(function (tab) { return tab.dataset.ulhgrTab === key; });
            if (!selected) {
                return;
            }
            tabs.forEach(function (tab) {
                const active = tab === selected;
                tab.classList.toggle('is-active', active);
                tab.setAttribute('aria-selected', active ? 'true' : 'false');
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach(function (panel) {
                panel.hidden = panel.dataset.ulhgrPanel !== key;
            });
            admin.dataset.view = key;
            if (updateHistory) {
                const url = new URL(selected.href);
                window.history.pushState({ ulhgrTab: key }, '', url);
            }
            if (focusTab) {
                selected.focus();
            }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                showTab(tab.dataset.ulhgrTab, true, false);
            });
            tab.addEventListener('keydown', function (event) {
                let next = index;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    showTab(tab.dataset.ulhgrTab, true, false);
                    return;
                } else return;
                event.preventDefault();
                showTab(tabs[next].dataset.ulhgrTab, true, true);
            });
        });

        window.addEventListener('popstate', function () {
            const key = new URL(window.location.href).searchParams.get('tab') || 'overview';
            showTab(key, false, false);
        });
    }

    function initPermissionSearch() {
        const search = document.querySelector('.ulhgr-user-search');
        if (!search) {
            return;
        }
        const choices = Array.from(document.querySelectorAll('.ulhgr-user-choice'));
        search.addEventListener('input', function () {
            const term = search.value.trim().toLocaleLowerCase();
            choices.forEach(function (choice) {
                choice.hidden = !choice.textContent.toLocaleLowerCase().includes(term);
            });
        });
    }

    function initScheduler() {
        const container = document.querySelector('.ulhgr-profiles');
        if (!container) {
            return;
        }

        const weekSelectors = Array.from(document.querySelectorAll('.ulhgr-day-profile'));
        let profileIndex = Math.max(-1, ...Array.from(container.querySelectorAll('.ulhgr-profile-name-input')).map(function (input) {
            const match = input.name.match(/\[profiles\]\[(\d+)\]/);
            return match ? Number(match[1]) : -1;
        })) + 1;
        let serial = 0;

        function profiles() {
            return Array.from(container.querySelectorAll('.ulhgr-profile'));
        }

        function resizeMessage(field) {
            field.style.height = '42px';
            field.style.height = Math.min(Math.max(field.scrollHeight + 2, 42), 112) + 'px';
        }

        function syncTimingRow(row) {
            const type = row.querySelector('.ulhgr-entry-type');
            const time = row.querySelector('.ulhgr-time-field input');
            const label = row.querySelector('input[name$="[label]"]');
            const event = row.querySelector('.ulhgr-event-field select');
            const message = row.querySelector('.ulhgr-message-field textarea');
            const untimed = type.value === 'untimed';

            row.classList.toggle('is-untimed', untimed);
            time.disabled = untimed;
            time.required = !untimed;
            label.required = untimed;
            label.placeholder = untimed ? ulhgrAdmin.untimedLabelExample : ulhgrAdmin.optional;
            message.disabled = untimed;
            event.disabled = untimed;
            if (untimed) {
                if (!event.dataset.beforeUntimed) {
                    event.dataset.beforeUntimed = event.value;
                }
                event.value = 'closing';
            } else if (Object.prototype.hasOwnProperty.call(event.dataset, 'beforeUntimed')) {
                event.value = event.dataset.beforeUntimed;
                delete event.dataset.beforeUntimed;
            }
        }

        container.querySelectorAll('.ulhgr-message-field textarea').forEach(resizeMessage);
        container.querySelectorAll('.ulhgr-interval-row').forEach(syncTimingRow);

        function updateChoices() {
            const choices = profiles().map(function (profile) {
                return { id: profile.dataset.profileId, name: profile.querySelector('.ulhgr-profile-name-input').value };
            });
            weekSelectors.forEach(function (select) {
                const previous = select.value;
                select.replaceChildren();
                choices.forEach(function (choice) {
                    select.add(new Option(choice.name, choice.id));
                });
                select.value = choices.some(function (choice) { return choice.id === previous; }) ? previous : choices[0].id;
                const summary = select.closest('.ulhgr-day-row').querySelector('.ulhgr-day-summary');
                summary.textContent = choices.find(function (choice) { return choice.id === select.value; }).name;
            });
            profiles().forEach(function (profile) {
                const assigned = weekSelectors.some(function (select) { return select.value === profile.dataset.profileId; });
                profile.querySelector('.ulhgr-remove-profile').disabled = assigned || choices.length === 1;
                const rows = profile.querySelectorAll('.ulhgr-interval-row');
                rows.forEach(function (row) {
                    row.querySelector('.ulhgr-remove-interval').disabled = rows.length === 1;
                });
                profile.querySelector('.ulhgr-add-interval').disabled = rows.length >= 24;
            });
            document.querySelector('.ulhgr-add-profile').disabled = choices.length >= 14;
        }

        function copyProfile(source, name) {
            if (profiles().length >= 14) {
                return null;
            }
            const clone = source.cloneNode(true);
            const index = profileIndex++;
            const id = 'schedule-' + Date.now().toString(36) + '-' + (++serial);
            clone.dataset.profileId = id;
            clone.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/\[profiles\]\[\d+\]/, '[profiles][' + index + ']');
            });
            clone.querySelector('.ulhgr-profile-id').value = id;
            clone.querySelector('.ulhgr-profile-name-input').value = name;
            container.append(clone);
            clone.querySelectorAll('.ulhgr-message-field textarea').forEach(resizeMessage);
            updateChoices();
            return clone;
        }

        document.querySelector('.ulhgr-add-profile').addEventListener('click', function () {
            const clone = copyProfile(profiles()[0], ulhgrAdmin.newSchedule);
            if (clone) {
                clone.querySelector('.ulhgr-profile-name-input').focus();
            }
        });

        document.querySelectorAll('.ulhgr-customize-day').forEach(function (button) {
            button.addEventListener('click', function () {
                const select = button.closest('.ulhgr-day-row').querySelector('.ulhgr-day-profile');
                const source = profiles().find(function (profile) { return profile.dataset.profileId === select.value; });
                const name = ulhgrAdmin.daySchedule.replace('%s', button.dataset.dayName);
                const clone = copyProfile(source, name);
                if (clone) {
                    select.value = clone.dataset.profileId;
                    updateChoices();
                    clone.querySelector('.ulhgr-profile-name-input').focus();
                }
            });
        });

        weekSelectors.forEach(function (select) { select.addEventListener('change', updateChoices); });
        container.addEventListener('input', function (event) {
            if (event.target.matches('.ulhgr-profile-name-input')) {
                updateChoices();
            } else if (event.target.matches('.ulhgr-message-field textarea')) {
                resizeMessage(event.target);
            }
        });
        container.addEventListener('change', function (event) {
            if (event.target.matches('.ulhgr-entry-type')) {
                syncTimingRow(event.target.closest('.ulhgr-interval-row'));
            }
        });
        container.addEventListener('click', function (event) {
            const profile = event.target.closest('.ulhgr-profile');
            if (!profile) {
                return;
            }
            if (event.target.closest('.ulhgr-remove-profile')) {
                profile.remove();
                updateChoices();
            } else if (event.target.closest('.ulhgr-add-interval')) {
                const rows = profile.querySelector('.ulhgr-intervals');
                if (rows.children.length >= 24) {
                    return;
                }
                const clone = rows.lastElementChild.cloneNode(true);
                const indexes = Array.from(rows.querySelectorAll('[name]')).map(function (field) {
                    const match = field.name.match(/\[intervals\]\[(\d+)\]/);
                    return match ? Number(match[1]) : -1;
                });
                const next = Math.max(-1, ...indexes) + 1;
                clone.querySelectorAll('[name]').forEach(function (field) {
                    field.name = field.name.replace(/\[intervals\]\[\d+\]/, '[intervals][' + next + ']');
                    field.value = field.tagName === 'SELECT' ? '' : '';
                });
                clone.querySelector('.ulhgr-entry-type').value = 'timed';
                clone.querySelector('.ulhgr-event-field select').value = '';
                delete clone.querySelector('.ulhgr-event-field select').dataset.beforeUntimed;
                rows.append(clone);
                syncTimingRow(clone);
                resizeMessage(clone.querySelector('.ulhgr-message-field textarea'));
                clone.querySelector('input[type="time"]').focus();
                updateChoices();
            } else if (event.target.closest('.ulhgr-remove-interval')) {
                event.target.closest('.ulhgr-interval-row').remove();
                updateChoices();
            }
        });
        updateChoices();
    }

    function initPreview() {
        const panel = document.querySelector('.ulhgr-preview');
        if (!panel) {
            return;
        }
        const button = panel.querySelector('.ulhgr-preview-button');
        const status = panel.querySelector('.ulhgr-preview-status');
        button.addEventListener('click', async function () {
            const date = panel.querySelector('.ulhgr-preview-date').value;
            const time = panel.querySelector('.ulhgr-preview-time').value;
            if (!date || !time) {
                return;
            }
            button.disabled = true;
            status.textContent = '';
            try {
                const cards = Array.from(panel.querySelectorAll('.ulhgr-example'));
                const results = await Promise.all(cards.map(async function (card) {
                    const url = new URL(ulhgrAdmin.restUrl);
                    url.searchParams.set('display', card.dataset.display);
                    url.searchParams.set('at', date + ' ' + time);
                    const response = await fetch(url.toString(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-WP-Nonce': ulhgrAdmin.nonce }
                    });
                    if (!response.ok) {
                        throw new Error('Preview request failed');
                    }
                    return response.json();
                }));
                results.forEach(function (result, index) {
                    const template = document.createElement('template');
                    template.innerHTML = result.html;
                    template.content.querySelectorAll('[data-ulhgr-transition],[data-ulhgr-target]').forEach(function (element) {
                        element.removeAttribute('data-ulhgr-transition');
                        element.removeAttribute('data-ulhgr-target');
                    });
                    cards[index].querySelector('.ulhgr-preview-value').replaceChildren(template.content);
                });
            } catch (error) {
                status.textContent = ulhgrAdmin.previewError;
            } finally {
                button.disabled = false;
            }
        });
    }

    function initSpecialDates() {
        const container = document.querySelector('.ulhgr-special-dates');
        const addDate = document.querySelector('.ulhgr-add-special-date');
        if (!container || !addDate) return;

        function windowMarkup(base, index, open, close) {
            return '<div class="ulhgr-special-window">' +
                '<label>' + ulhgrAdmin.opens + '<input type="time" name="' + base + '[windows][' + index + '][open]" value="' + open + '"></label>' +
                '<label>' + ulhgrAdmin.closes + '<input type="time" name="' + base + '[windows][' + index + '][close]" value="' + close + '"></label>' +
                '<button type="button" class="button ulhgr-remove-special-window" aria-label="Remove hours window">&times;</button></div>';
        }

        function syncCard(card) {
            const closed = card.querySelector('.ulhgr-special-closed').checked;
            const windows = card.querySelector('.ulhgr-special-windows');
            windows.hidden = closed;
            card.querySelector('.ulhgr-add-special-window').hidden = closed;
            card.querySelector('.ulhgr-special-date-state').textContent = closed ? ulhgrAdmin.closed : ulhgrAdmin.customHours;
            const label = card.querySelector('.ulhgr-special-label-input').value.trim() || ulhgrAdmin.specialDate;
            card.querySelector('summary strong').textContent = label;
            card.querySelector('summary .ulhgr-special-date-summary span').textContent = card.querySelector('.ulhgr-special-date-input').value;
            const removeButtons = card.querySelectorAll('.ulhgr-remove-special-window');
            removeButtons.forEach(function (button) { button.disabled = removeButtons.length === 1; });
        }

        addDate.addEventListener('click', function () {
            const index = Number(container.dataset.nextIndex || 0);
            container.dataset.nextIndex = String(index + 1);
            const base = 'ulhgr_settings[special_dates][' + index + ']';
            const card = document.createElement('details');
            card.className = 'ulhgr-special-date';
            card.open = true;
            card.innerHTML = '<summary><span class="ulhgr-special-date-summary"><strong>' + ulhgrAdmin.specialDate + '</strong><span></span></span><span class="ulhgr-special-date-state">' + ulhgrAdmin.customHours + '</span></summary>' +
                '<div class="ulhgr-special-date-body"><div class="ulhgr-special-date-fields">' +
                '<label>' + ulhgrAdmin.date + '<input type="date" class="ulhgr-special-date-input" name="' + base + '[date]" required></label>' +
                '<label>' + ulhgrAdmin.label + '<input type="text" class="ulhgr-special-label-input" name="' + base + '[label]" maxlength="100"></label></div>' +
                '<label class="ulhgr-checkbox-field"><input type="hidden" name="' + base + '[closed]" value="0"><input type="checkbox" class="ulhgr-special-closed" name="' + base + '[closed]" value="1">' + ulhgrAdmin.closedAllDay + '</label>' +
                '<div class="ulhgr-special-windows">' + windowMarkup(base, 0, '09:00', '17:00') + '</div>' +
                '<button type="button" class="button ulhgr-add-special-window">' + ulhgrAdmin.addHoursWindow + '</button>' +
                '<label class="ulhgr-special-message">' + ulhgrAdmin.messageOptional + '<textarea name="' + base + '[message]" rows="2"></textarea></label>' +
                '<button type="button" class="button ulhgr-remove-special-date">' + ulhgrAdmin.removeSpecialDate + '</button></div>';
            container.append(card);
            syncCard(card);
            card.querySelector('.ulhgr-special-date-input').focus();
        });

        container.addEventListener('input', function (event) {
            const card = event.target.closest('.ulhgr-special-date');
            if (card) syncCard(card);
        });
        container.addEventListener('change', function (event) {
            const card = event.target.closest('.ulhgr-special-date');
            if (card) syncCard(card);
        });
        container.addEventListener('click', function (event) {
            const card = event.target.closest('.ulhgr-special-date');
            if (!card) return;
            if (event.target.closest('.ulhgr-remove-special-date')) {
                card.remove();
            } else if (event.target.closest('.ulhgr-add-special-window')) {
                const windows = card.querySelector('.ulhgr-special-windows');
                if (windows.children.length >= 6) return;
                const base = card.querySelector('.ulhgr-special-date-input').name.replace(/\[date\]$/, '');
                windows.insertAdjacentHTML('beforeend', windowMarkup(base, windows.children.length, '09:00', '17:00'));
                syncCard(card);
            } else if (event.target.closest('.ulhgr-remove-special-window')) {
                event.target.closest('.ulhgr-special-window').remove();
                syncCard(card);
            }
        });
        container.querySelectorAll('.ulhgr-special-date').forEach(syncCard);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initTabs(); initToast(); initPermissionSearch(); initScheduler(); initSpecialDates(); initPreview(); });
    } else {
        initTabs();
        initToast();
        initPermissionSearch();
        initScheduler();
        initSpecialDates();
        initPreview();
    }
})();
