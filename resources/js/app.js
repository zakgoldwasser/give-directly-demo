import { initUpload } from './modules/upload.js';

document.addEventListener('DOMContentLoaded', () => {
    initUpload(document);

    document.querySelectorAll('[data-scenario-form]').forEach((form) => {
        const button = form.querySelector('button');
        const label = form.querySelector('[data-scenario-label]');
        const initiallyDisabled = button.disabled;
        const initialLabel = label.textContent;

        window.addEventListener('pageshow', () => {
            button.disabled = initiallyDisabled;
            label.textContent = initialLabel;
            document.querySelector('[data-scenario-status]').textContent = '';
        });

        form.addEventListener('submit', () => {
            document.querySelectorAll('[data-scenario-form] button').forEach((button) => {
                button.disabled = true;
            });
            form.querySelector('[data-scenario-label]').textContent = 'Loading three reports…';
            document.querySelector('[data-scenario-status]').textContent = 'Loading your sample and checking the reports…';
        });
    });
});
