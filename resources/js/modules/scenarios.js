export function initScenarios(root = document) {
    const dialogs = [...root.querySelectorAll('[data-scenario-preview]')];
    const forms = [...root.querySelectorAll('[data-scenario-form]')];
    let loading = false;
    let readyUrl = null;
    let restoreControls = () => {};

    function message(selector, text) {
        root.querySelectorAll(selector).forEach((element) => {
            element.textContent = text;
        });
    }

    function showResults(url) {
        root.querySelectorAll('[data-scenario-results]').forEach((link) => {
            link.href = url;
            link.hidden = false;
        });
    }

    dialogs.forEach((dialog) => {
        let opener;
        root.querySelectorAll(`[data-preview-open="${dialog.dataset.scenarioPreview}"]`).forEach((button) => {
            button.addEventListener('click', () => {
                opener = button;
                dialog.showModal();
            });
        });
        dialog.querySelector('[data-preview-close]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => opener?.focus());

        const tabs = [...dialog.querySelectorAll('[data-preview-tab]')];
        const panels = [...dialog.querySelectorAll('[data-preview-panel]')];
        function selectTab(selected) {
            tabs.forEach((tab) => {
                const active = tab === selected;
                tab.setAttribute('aria-selected', String(active));
                tab.tabIndex = active ? 0 : -1;
            });
            panels.forEach((panel) => {
                panel.hidden = panel.dataset.previewPanel !== selected.dataset.previewTab;
            });
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectTab(tab));
            tab.addEventListener('keydown', (event) => {
                const next = {
                    ArrowRight: (index + 1) % tabs.length,
                    ArrowLeft: (index + tabs.length - 1) % tabs.length,
                    Home: 0,
                    End: tabs.length - 1,
                }[event.key];
                if (next === undefined) return;
                event.preventDefault();
                selectTab(tabs[next]);
                tabs[next].focus();
            });
        });
        dialog.querySelector('[data-highlight-filter]').addEventListener('change', (event) => {
            panels.forEach((panel) => {
                const rows = [...panel.querySelectorAll('[data-preview-row]')];
                rows.forEach((row) => {
                    row.hidden = event.target.checked && row.dataset.highlighted !== 'true';
                });
                const visible = rows.filter((row) => !row.hidden).length;
                panel.querySelector('[data-preview-count]').textContent = `Showing ${visible} of ${rows.length} rows`;
            });
        });
    });

    forms.forEach((form) => {
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (loading || readyUrl) return;

            const body = new FormData(form);
            const label = form.querySelector('[data-scenario-label]');
            const originalLabel = label.textContent;
            const controls = [...root.querySelectorAll('form button[type="submit"], form button:not([type]), form input[type="submit"]')];
            const disabledStates = controls.map((control) => control.disabled);
            restoreControls = () => {
                controls.forEach((control, index) => { control.disabled = disabledStates[index]; });
                label.textContent = originalLabel;
            };
            controls.forEach((control) => { control.disabled = true; });
            root.querySelector('[data-scenario-feedback]').hidden = false;
            loading = true;
            label.textContent = 'Loading three reports…';
            message('[data-scenario-error]', '');
            message('[data-scenario-status]', `Loading “${form.dataset.scenarioTitle}”. You can view any sample’s CSVs while we check the reports.`);

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body,
                    credentials: 'same-origin',
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const error = response.status === 419
                        ? 'Your session expired. Refresh the page before loading a sample.'
                        : response.status === 429
                            ? 'Too many sample starts. Wait a minute, then try again.'
                            : response.status >= 500
                                ? 'The sample could not finish loading. Please try again.'
                                : payload?.error?.message || payload?.message || 'The sample could not be loaded. Please try again.';
                    throw new Error(error);
                }
                const redirect = payload?.data?.redirect;
                if (!redirect || new URL(redirect, window.location.href).origin !== window.location.origin) {
                    throw new Error('The sample response was incomplete. Refresh the page to check your workspace.');
                }
                readyUrl = redirect;
                label.textContent = 'Results ready';
                message('[data-scenario-status]', `Results ready for “${form.dataset.scenarioTitle}”. View reconciliation when you’re ready.`);
                showResults(redirect);
                if (!dialogs.some((dialog) => dialog.open)) {
                    window.location.assign(redirect);
                }
            } catch (error) {
                message('[data-scenario-status]', '');
                message('[data-scenario-error]', error instanceof TypeError
                    ? 'Connection interrupted. You can keep viewing the CSVs. Refresh to check your workspace, or try loading again.'
                    : error.message);
                restoreControls();
            } finally {
                loading = false;
            }
        });
    });

    window.addEventListener('pageshow', (event) => {
        if (event.persisted) {
            restoreControls();
            loading = false;
            readyUrl = null;
            message('[data-scenario-status]', '');
            message('[data-scenario-error]', '');
            root.querySelectorAll('[data-scenario-results]').forEach((link) => { link.hidden = true; });
            const feedback = root.querySelector('[data-scenario-feedback]');
            if (feedback) feedback.hidden = true;
        }
    });
}
