import { initProcessing } from './modules/processing.js';
import { initUpload } from './modules/upload.js';

document.addEventListener('DOMContentLoaded', () => {
    initUpload(document);
    initProcessing(document);
});
