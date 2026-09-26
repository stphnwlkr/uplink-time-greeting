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

    function initTabs() {
        const admin = document.querySelector('.tgb-admin');
        const tabs = Array.from(document.querySelectorAll('[data-utg-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-utg-panel]'));
        if (!admin || !tabs.length || !panels.length) {
            return;
        }

        function showTab(key, updateHistory, focusTab) {
            const selected = tabs.find(function (tab) { return tab.dataset.utgTab === key; });
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
                panel.hidden = panel.dataset.utgPanel !== key;
            });
            admin.dataset.view = key;
            if (updateHistory) {
                const url = new URL(selected.href);
                window.history.pushState({ utgTab: key }, '', url);
            }
            if (focusTab) {
                selected.focus();
            }
        }

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                showTab(tab.dataset.utgTab, true, false);
            });
            tab.addEventListener('keydown', function (event) {
                let next = index;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (index + 1) % tabs.length;
                else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (index - 1 + tabs.length) % tabs.length;
                else if (event.key === 'Home') next = 0;
                else if (event.key === 'End') next = tabs.length - 1;
                else if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    showTab(tab.dataset.utgTab, true, false);
                    return;
                } else return;
                event.preventDefault();
                showTab(tabs[next].dataset.utgTab, true, true);
            });
        });

        window.addEventListener('popstate', function () {
            const key = new URL(window.location.href).searchParams.get('tab') || 'overview';
            showTab(key, false, false);
        });
    }

    function initPermissionSearch() {
        const search = document.querySelector('.utg-user-search');
        if (!search) {
            return;
        }
        const choices = Array.from(document.querySelectorAll('.utg-user-choice'));
        search.addEventListener('input', function () {
            const term = search.value.trim().toLocaleLowerCase();
            choices.forEach(function (choice) {
                choice.hidden = !choice.textContent.toLocaleLowerCase().includes(term);
            });
        });
    }

    function initScheduler() {
        const container = document.querySelector('.utg-profiles');
        if (!container) {
            return;
        }

        const weekSelectors = Array.from(document.querySelectorAll('.utg-day-profile'));
        let profileIndex = Math.max(-1, ...Array.from(container.querySelectorAll('.utg-profile-name-input')).map(function (input) {
            const match = input.name.match(/\[profiles\]\[(\d+)\]/);
            return match ? Number(match[1]) : -1;
        })) + 1;
        let serial = 0;

        function profiles() {
            return Array.from(container.querySelectorAll('.utg-profile'));
        }

        function resizeMessage(field) {
            field.style.height = '42px';
            field.style.height = Math.min(Math.max(field.scrollHeight + 2, 42), 112) + 'px';
        }

        container.querySelectorAll('.utg-message-field textarea').forEach(resizeMessage);

        function updateChoices() {
            const choices = profiles().map(function (profile) {
                return { id: profile.dataset.profileId, name: profile.querySelector('.utg-profile-name-input').value };
            });
            weekSelectors.forEach(function (select) {
                const previous = select.value;
                select.replaceChildren();
                choices.forEach(function (choice) {
                    select.add(new Option(choice.name, choice.id));
                });
                select.value = choices.some(function (choice) { return choice.id === previous; }) ? previous : choices[0].id;
                const summary = select.closest('.utg-day-row').querySelector('.utg-day-summary');
                summary.textContent = choices.find(function (choice) { return choice.id === select.value; }).name;
            });
            profiles().forEach(function (profile) {
                const assigned = weekSelectors.some(function (select) { return select.value === profile.dataset.profileId; });
                profile.querySelector('.utg-remove-profile').disabled = assigned || choices.length === 1;
                const rows = profile.querySelectorAll('.utg-interval-row');
                rows.forEach(function (row) {
                    row.querySelector('.utg-remove-interval').disabled = rows.length === 1;
                });
                profile.querySelector('.utg-add-interval').disabled = rows.length >= 24;
            });
            document.querySelector('.utg-add-profile').disabled = choices.length >= 14;
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
            clone.querySelector('.utg-profile-id').value = id;
            clone.querySelector('.utg-profile-name-input').value = name;
            container.append(clone);
            clone.querySelectorAll('.utg-message-field textarea').forEach(resizeMessage);
            updateChoices();
            return clone;
        }

        document.querySelector('.utg-add-profile').addEventListener('click', function () {
            const clone = copyProfile(profiles()[0], utgAdmin.newSchedule);
            if (clone) {
                clone.querySelector('.utg-profile-name-input').focus();
            }
        });

        document.querySelectorAll('.utg-customize-day').forEach(function (button) {
            button.addEventListener('click', function () {
                const select = button.closest('.utg-day-row').querySelector('.utg-day-profile');
                const source = profiles().find(function (profile) { return profile.dataset.profileId === select.value; });
                const name = utgAdmin.daySchedule.replace('%s', button.dataset.dayName);
                const clone = copyProfile(source, name);
                if (clone) {
                    select.value = clone.dataset.profileId;
                    updateChoices();
                    clone.querySelector('.utg-profile-name-input').focus();
                }
            });
        });

        weekSelectors.forEach(function (select) { select.addEventListener('change', updateChoices); });
        container.addEventListener('input', function (event) {
            if (event.target.matches('.utg-profile-name-input')) {
                updateChoices();
            } else if (event.target.matches('.utg-message-field textarea')) {
                resizeMessage(event.target);
            }
        });
        container.addEventListener('click', function (event) {
            const profile = event.target.closest('.utg-profile');
            if (!profile) {
                return;
            }
            if (event.target.closest('.utg-remove-profile')) {
                profile.remove();
                updateChoices();
            } else if (event.target.closest('.utg-add-interval')) {
                const rows = profile.querySelector('.utg-intervals');
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
                rows.append(clone);
                resizeMessage(clone.querySelector('.utg-message-field textarea'));
                clone.querySelector('input[type="time"]').focus();
                updateChoices();
            } else if (event.target.closest('.utg-remove-interval')) {
                event.target.closest('.utg-interval-row').remove();
                updateChoices();
            }
        });
        updateChoices();
    }

    function initPreview() {
        const panel = document.querySelector('.tgb-preview');
        if (!panel) {
            return;
        }
        const button = panel.querySelector('.utg-preview-button');
        const status = panel.querySelector('.utg-preview-status');
        button.addEventListener('click', async function () {
            const date = panel.querySelector('.utg-preview-date').value;
            const time = panel.querySelector('.utg-preview-time').value;
            if (!date || !time) {
                return;
            }
            button.disabled = true;
            status.textContent = '';
            try {
                const cards = Array.from(panel.querySelectorAll('.tgb-example'));
                const results = await Promise.all(cards.map(async function (card) {
                    const url = new URL(utgAdmin.restUrl);
                    url.searchParams.set('display', card.dataset.display);
                    url.searchParams.set('at', date + ' ' + time);
                    const response = await fetch(url.toString(), {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-WP-Nonce': utgAdmin.nonce }
                    });
                    if (!response.ok) {
                        throw new Error('Preview request failed');
                    }
                    return response.json();
                }));
                results.forEach(function (result, index) {
                    const template = document.createElement('template');
                    template.innerHTML = result.html;
                    template.content.querySelectorAll('[data-utg-transition],[data-utg-target]').forEach(function (element) {
                        element.removeAttribute('data-utg-transition');
                        element.removeAttribute('data-utg-target');
                    });
                    cards[index].querySelector('.tgb-preview-value').replaceChildren(template.content);
                });
            } catch (error) {
                status.textContent = utgAdmin.previewError;
            } finally {
                button.disabled = false;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initTabs(); initToast(); initPermissionSearch(); initScheduler(); initPreview(); });
    } else {
        initTabs();
        initToast();
        initPermissionSearch();
        initScheduler();
        initPreview();
    }
})();
