import { initUpload } from './modules/upload.js';
import { initScenarios } from './modules/scenarios.js';

document.addEventListener('DOMContentLoaded', () => {
    initUpload(document);

    initScenarios(document);
});
