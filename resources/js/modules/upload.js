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
    const form = root.querySelector('[data-upload-form]');

    if (!form) {
        return;
    }

    const dropZone = form.querySelector('[data-drop-zone]');
    const fileInput = form.querySelector('[data-file-input]');
    const fileLabel = form.querySelector('[data-file-label]');
    const errorEl = form.querySelector('[data-upload-error]');
    const progressEl = form.querySelector('[data-upload-progress]');
    const submitButton = form.querySelector('[data-upload-submit]');
    const maxBytes = Number(form.dataset.maxBytes || 0);

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
        submitButton?.setAttribute('disabled', 'disabled');
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
                window.location.assign(payload.data.redirect);

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
                window.location.assign(redirect);

                return;
            }

            showError('Upload succeeded but no redirect was returned.');
        } catch (error) {
            showError('Network error while uploading. Try again.');
            setText(progressEl, '');
            progressEl?.classList.add('hidden');
        } finally {
            submitButton?.removeAttribute('disabled');
        }
    });
}
