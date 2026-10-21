import { setBusy } from './feedback.js';

function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');

    return meta ? meta.getAttribute('content') : '';
}

function setText(element, value) {
    if (element) {
        element.textContent = value;
    }
}

function formatBytes(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(2)} MB`;
}

export function initUpload(root = document) {
    root.querySelectorAll('[data-upload-form]').forEach((form) => initUploadForm(form));
}

function initUploadForm(form) {

    const dropZone = form.querySelector('[data-drop-zone]');
    const fileInput = form.querySelector('[data-file-input]');
    const fileLabel = form.querySelector('[data-file-label]');
    const errorEl = form.querySelector('[data-upload-error]');
    const progressEl = form.querySelector('[data-upload-progress]');
    const submitButton = form.querySelector('[data-upload-submit]');
    const maxBytes = Number(form.dataset.maxBytes || 0);
    let busy = false;
    let navigating = false;
    let restoreControls = () => {};

    window.addEventListener('pageshow', () => {
        restoreControls();
        busy = false;
        navigating = false;
        setText(progressEl, '');
        progressEl?.classList.add('hidden');
    });

    function showError(message) {
        if (!errorEl) {
            return;
        }

        errorEl.classList.remove('hidden');
        setText(errorEl, message);
    }

    function clearError() {
        if (!errorEl) {
            return;
        }

        errorEl.classList.add('hidden');
        setText(errorEl, '');
    }

    function assignFile(file) {
        clearError();

        if (!file) {
            setText(fileLabel, 'No file selected');

            return;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
            showError(`File is too large. Maximum size is ${formatBytes(maxBytes)}.`);
            fileInput.value = '';
            setText(fileLabel, 'No file selected');

            return;
        }

        const transfer = new DataTransfer();
        transfer.items.add(file);
        fileInput.files = transfer.files;
        setText(fileLabel, `${file.name} (${formatBytes(file.size)})`);
    }

    fileInput?.addEventListener('change', () => {
        assignFile(fileInput.files?.[0] ?? null);
    });

    ['dragenter', 'dragover'].forEach((eventName) => {
        dropZone?.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropZone.classList.add('border-emerald-500', 'bg-emerald-50');
        });
    });

    ['dragleave', 'drop'].forEach((eventName) => {
        dropZone?.addEventListener(eventName, (event) => {
            event.preventDefault();
            dropZone.classList.remove('border-emerald-500', 'bg-emerald-50');
        });
    });

    dropZone?.addEventListener('drop', (event) => {
        const file = event.dataTransfer?.files?.[0] ?? null;
        assignFile(file);
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (busy || submitButton.disabled) return;
        clearError();

        const file = fileInput?.files?.[0];

        if (!file) {
            showError('Choose a CSV file to upload.');

            return;
        }

        if (maxBytes > 0 && file.size > maxBytes) {
            showError(`File is too large. Maximum size is ${formatBytes(maxBytes)}.`);

            return;
        }

        const body = new FormData(form);
        busy = true;
        const restoreButton = setBusy(submitButton, 'Uploading…');
        const controls = [...document.querySelectorAll('form button[type="submit"], form button:not([type]), form input[type="submit"]')]
            .filter((control) => control !== submitButton);
        const disabledStates = controls.map((control) => control.disabled);
        restoreControls = () => {
            controls.forEach((control, index) => { control.disabled = disabledStates[index]; });
            restoreButton();
        };
        controls.forEach((control) => { control.disabled = true; });
        progressEl?.classList.remove('hidden');
        setText(progressEl, 'Uploading…');

        try {
            const response = await fetch(form.dataset.uploadUrl, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body,
                credentials: 'same-origin',
            });

            const payload = await response.json().catch(() => ({}));

            if (response.status === 409 && payload?.data?.redirect) {
                setBusy(submitButton, 'Opening import…');
                setText(progressEl, 'Opening existing import…');
                window.location.assign(payload.data.redirect);
                navigating = true;

                return;
            }

            if (!response.ok) {
                const message = payload?.error?.message
                    || payload?.message
                    || 'Upload failed. Check the file and try again.';
                showError(message);
                setText(progressEl, '');
                progressEl?.classList.add('hidden');

                return;
            }

            const redirect = payload?.data?.redirect;

            if (redirect) {
                setText(progressEl, 'Upload complete. Opening import…');
                setBusy(submitButton, 'Opening import…');
                window.location.assign(redirect);
                navigating = true;

                return;
            }

            showError('Upload succeeded but no redirect was returned.');
        } catch (error) {
            showError('Network error while uploading. Try again.');
            setText(progressEl, '');
            progressEl?.classList.add('hidden');
        } finally {
            if (!navigating) {
                busy = false;
                restoreControls();
                setText(progressEl, '');
                progressEl?.classList.add('hidden');
            }
        }
    });
}
