import { initUpload } from './modules/upload.js';
import { initScenarios } from './modules/scenarios.js';
import { initFeedback } from './modules/feedback.js';
import { initImportModal } from './modules/import-modal.js';

document.addEventListener('DOMContentLoaded', () => {
    const openImport = initImportModal(document);
    initUpload(document, { onUploaded: openImport });

    initScenarios(document);
    initFeedback(document);
});
