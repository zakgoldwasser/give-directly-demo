import { setBusy } from './feedback.js';

export function initImportModal(root = document) {
    const dialog = root.querySelector('[data-import-modal]');
    if (!dialog || typeof dialog.showModal !== 'function') return null;

    const content = dialog.querySelector('[data-import-content]');
    const progress = dialog.querySelector('[data-import-progress]');
    const errors = dialog.querySelector('[data-import-error]');
    const closeButton = dialog.querySelector('[data-import-close]');
    const nextButton = dialog.querySelector('[data-import-next]');
    const guidance = dialog.querySelector('[data-import-guidance]');
    const fallback = dialog.querySelector('[data-import-fallback]');
    let currentUrl;
    let opener;
    let reportType;
    let saving = false;
    let refreshWorkspace = false;
    let nextType = null;
    let requestVersion = 0;

    function showError(message) {
        errors.textContent = message;
        errors.hidden = false;
        if (dialog.open) dialog.querySelector('[data-import-scroll]').scrollTop = 0;
    }

    function updateGuidance(status) {
        nextType = null;
        nextButton.hidden = status !== 'validated';
        guidance.textContent = 'Your uploaded file is saved. You can close this window and review it later.';
        if (status !== 'validated') return;
        const cards = [...root.querySelectorAll('[data-report-card]')];
        const nextCard = cards.find((card) => card.querySelector('[data-report-status]')?.dataset.reportStatus !== 'validated');
        nextType = nextCard?.dataset.reportCard ?? null;
        const label = { donations: 'donations', payouts: 'payouts', crm: 'CRM snapshot' }[nextType];
        guidance.textContent = nextType ? `Report validated. Next, add or review your ${label}.` : 'Reports are ready. View the latest reconciliation results.';
        nextButton.textContent = nextType ? `Continue to ${label}` : 'View reconciliation';
    }

    function bindContent() {
        const tabs = [...content.querySelectorAll('[data-import-tab]')];
        function selectTab(tab, focus = false) {
            tabs.forEach((item) => {
                item.setAttribute('aria-selected', String(item === tab));
                item.tabIndex = item === tab ? 0 : -1;
            });
            content.querySelectorAll('[data-import-panel]').forEach((panel) => {
                panel.hidden = panel.dataset.importPanel !== tab.dataset.importTab;
            });
            if (focus) tab.focus();
        }
        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectTab(tab));
            tab.addEventListener('keydown', (event) => {
                const next = { ArrowRight: (index + 1) % tabs.length, ArrowLeft: (index + tabs.length - 1) % tabs.length, Home: 0, End: tabs.length - 1 }[event.key];
                if (next === undefined) return;
                event.preventDefault();
                selectTab(tabs[next], true);
            });
        });
        const form = content.querySelector('[data-mapping-form]');
        form?.addEventListener('change', () => {
            form.querySelector('[name="idempotency_key"]').value = crypto.randomUUID();
        });
        form?.addEventListener('submit', async (event) => {
            event.preventDefault();
            if (saving) return;
            const body = new FormData(form);
            saving = true;
            closeButton.disabled = true;
            nextButton.disabled = true;
            errors.hidden = true;
            progress.textContent = 'Saving mapping and validating report…';
            const restore = setBusy(form.querySelector('button[type="submit"]'), 'Validating report…');
            const fields = [...form.querySelectorAll('select')];
            fields.forEach((field) => { field.disabled = true; });
            try {
                const response = await fetch(form.action, { method: 'POST', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, body, credentials: 'same-origin' });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    const issues = payload?.error?.issues?.map((issue) => issue.message).join(' ');
                    throw new Error(issues || payload?.error?.message || payload?.message || 'The mapping could not be saved. Please try again.');
                }
                refreshWorkspace = true;
                await load(currentUrl);
                progress.textContent = 'Mapping saved and validation complete. Check the findings below or open Preview for row details.';
            } catch (error) {
                showError(error instanceof TypeError ? 'Connection interrupted. Retry to check or complete this mapping, or open the full page.' : error.message);
                progress.textContent = '';
            } finally {
                saving = false;
                closeButton.disabled = false;
                nextButton.disabled = false;
                fields.forEach((field) => { field.disabled = false; });
                restore();
            }
        });
    }

    async function load(url) {
        const version = ++requestVersion;
        const response = await fetch(url, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || !payload?.data?.html) throw new Error(payload?.error?.message || payload?.message || 'The report could not be opened. Use the full-page link or try again.');
        if (version !== requestVersion) return;
        const data = payload.data;
        reportType = data.report_type;
        content.innerHTML = data.html;
        const card = root.querySelector(`[data-report-card="${reportType}"]`);
        if (card) {
            card.querySelector('[data-report-state]').innerHTML = data.card_html;
            card.querySelector('[data-report-upload]').hidden = true;
        }
        updateGuidance(data.status);
        bindContent();
        progress.textContent = '';
        if (dialog.open) {
            dialog.querySelector('[data-import-scroll]').scrollTop = 0;
            content.querySelector('[data-import-tab]')?.focus();
        }
    }

    async function open(url, trigger, uploaded = false) {
        opener = trigger || document.activeElement;
        fallback.href = url;
        errors.hidden = true;
        if (!dialog.open) dialog.showModal();
        if (uploaded) refreshWorkspace = true;
        if (currentUrl === url && content.childElementCount) return;
        currentUrl = url;
        content.replaceChildren();
        nextButton.hidden = true;
        progress.textContent = 'Opening report…';
        try {
            await load(url);
        } catch (error) {
            progress.textContent = '';
            showError(error instanceof TypeError ? 'Connection interrupted. Your uploaded file is saved. Reopen the report or use the full-page link.' : error.message);
        }
    }

    root.addEventListener('click', (event) => {
        const link = event.target.closest('[data-import-open]');
        if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault();
        open(link.href, link);
    }, true);
    closeButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('cancel', (event) => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => {
        if (refreshWorkspace && window.location.pathname === new URL(dialog.dataset.workspaceUrl).pathname) {
            window.location.reload();
            return;
        }
        const reviewLink = root.querySelector(`[data-report-card="${reportType}"] [data-import-open]`);
        (reviewLink || opener)?.focus();
    });
    nextButton.addEventListener('click', () => {
        if (!nextType) {
            setBusy(nextButton, 'Opening reconciliation…');
            window.location.assign(dialog.dataset.workspaceUrl);
            return;
        }
        const card = root.querySelector(`[data-report-card="${nextType}"]`);
        const link = card?.querySelector('[data-import-open]');
        if (link) {
            open(link.href, link);
        } else {
            window.location.hash = `report-${nextType}`;
            dialog.close();
            card?.querySelector('[data-file-input]')?.focus();
        }
    });
    return open;
}
