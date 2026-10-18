function setText(element, value) {
    if (element) {
        element.textContent = String(value);
    }
}

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : '';
}

export function initProcessing(root = document) {
    const panel = root.querySelector('[data-processing-root]');

    if (!panel) {
        return;
    }

    const status = panel.dataset.batchStatus;
    const statusUrl = panel.dataset.statusUrl;

    if (!statusUrl || !['uploaded', 'queued', 'processing'].includes(status)) {
        return;
    }

    const processedEl = panel.querySelector('[data-processed-rows]');
    const totalEl = panel.querySelector('[data-total-rows]');
    const statusLabel = panel.querySelector('[data-status-label]');
    const progressBar = panel.querySelector('[data-progress-bar]');
    let stopped = false;

    async function poll() {
        if (stopped) {
            return;
        }

        try {
            const response = await fetch(statusUrl, {
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
            });

            if (response.status === 410) {
                window.location.reload();

                return;
            }

            if (!response.ok) {
                window.setTimeout(poll, 1500);

                return;
            }

            const payload = await response.json();
            const data = payload?.data ?? {};

            setText(statusLabel, String(data.status || '').replaceAll('_', ' '));
            setText(processedEl, data.processed_rows ?? 0);
            setText(totalEl, data.total_rows > 0 ? data.total_rows : '…');

            if (progressBar && data.total_rows > 0) {
                const percent = Math.min(100, Math.round((data.processed_rows / data.total_rows) * 100));
                progressBar.style.width = `${Math.max(percent, 8)}%`;
                progressBar.classList.remove('animate-pulse');
            }

            if (['mapping_required', 'failed', 'expired', 'completed'].includes(data.status)) {
                stopped = true;
                window.location.reload();

                return;
            }
        } catch (error) {
            // Keep polling through transient network errors.
        }

        window.setTimeout(poll, 1000);
    }

    window.setTimeout(poll, 500);
}
