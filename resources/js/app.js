import { initUpload } from './modules/upload.js';
import { initScenarios } from './modules/scenarios.js';
import { initFeedback } from './modules/feedback.js';

document.addEventListener('DOMContentLoaded', () => {
    initUpload(document);

    initScenarios(document);
    initFeedback(document);
});
