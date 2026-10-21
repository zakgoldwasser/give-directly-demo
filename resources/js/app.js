import { initUpload } from './modules/upload.js';
import { initScenarios } from './modules/scenarios.js';
import { initFeedback } from './modules/feedback.js';
import { initImportModal } from './modules/import-modal.js';

document.addEventListener('DOMContentLoaded', () => {
    const openImport = initImportModal(document);
    initUpload(document, { onUploaded: openImport });

    initScenarios(document);
    document.querySelectorAll('[data-match-review]').forEach((form) => {
        form.addEventListener('change', () => {
            const choice = form.querySelector('input[name="crm_record_id"]:checked');
            const rejecting = choice && choice.value === '';
            form.querySelector('button[type="submit"]').textContent = rejecting ? 'Reject CRM candidates' : 'Confirm CRM match';
            form.dataset.busyForm = rejecting ? 'Rejecting CRM candidates…' : 'Confirming match…';
        });
    });
    initFeedback(document);
});
