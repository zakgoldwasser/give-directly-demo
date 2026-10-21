const busyControls = new Map();

export function setBusy(control, text, spinner = true) {
    if (!control) return () => {};

    if (!busyControls.has(control)) {
        const children = [...control.childNodes];
        const disabled = control.disabled;
        const attributes = ['aria-busy', 'aria-disabled', 'aria-label'].map((name) => [name, control.getAttribute(name)]);
        busyControls.set(control, () => {
            control.replaceChildren(...children);
            if ('disabled' in control) control.disabled = disabled;
            attributes.forEach(([name, value]) => {
                if (value === null) control.removeAttribute(name);
                else control.setAttribute(name, value);
            });
        });
    }

    const label = document.createElement('span');
    label.textContent = text;
    control.replaceChildren(label);
    if (spinner) {
        const indicator = document.createElement('span');
        indicator.className = 'mr-2 inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-r-transparent align-middle motion-reduce:animate-none';
        indicator.setAttribute('aria-hidden', 'true');
        control.prepend(indicator);
    }
    if ('disabled' in control) control.disabled = true;
    control.setAttribute('aria-disabled', 'true');
    control.setAttribute('aria-busy', String(spinner));
    control.setAttribute('aria-label', text);

    return () => {
        busyControls.get(control)?.();
        busyControls.delete(control);
    };
}

export function initFeedback(root = document) {
    let submitting = false;
    let restoreSubmitControls = () => {};
    const downloadTimers = new Set();
    const announce = (text) => {
        const status = root.querySelector('[data-action-status]');
        if (status) status.textContent = text;
    };

    root.querySelectorAll('[data-busy-form]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (event.defaultPrevented) return;
            if (submitting) {
                event.preventDefault();
                return;
            }
            submitting = true;
            const controls = [...root.querySelectorAll('form button:not([type="button"]), form input[type="submit"]')];
            const states = controls.map((control) => control.disabled);
            restoreSubmitControls = () => controls.forEach((control, index) => { control.disabled = states[index]; });
            setBusy(event.submitter || form.querySelector('button:not([type="button"])'), form.dataset.busyForm);
            controls.forEach((control) => { control.disabled = true; });
            announce(form.dataset.busyForm);
        });
    });

    root.querySelectorAll('a[href]:not([data-scenario-results])').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            if (link.target && link.target !== '_self') return;
            const destination = new URL(link.href, window.location.href);
            if (destination.origin !== window.location.origin || !['http:', 'https:'].includes(destination.protocol)) return;
            if (busyControls.has(link)) {
                event.preventDefault();
                return;
            }
            if (link.hasAttribute('data-download-feedback') || link.hasAttribute('download')) {
                const restore = setBusy(link, 'Download requested', false);
                announce('Download requested. Check your browser’s downloads.');
                const timer = window.setTimeout(() => {
                    restore();
                    downloadTimers.delete(timer);
                }, 3000);
                downloadTimers.add(timer);
                return;
            }
            if (destination.pathname === window.location.pathname && destination.search === window.location.search && destination.hash) return;
            const text = link.dataset.busyLabel || 'Opening…';
            setBusy(link, text);
            announce(text);
        });
    });

    window.addEventListener('pageshow', () => {
        busyControls.forEach((restore) => restore());
        busyControls.clear();
        restoreSubmitControls();
        submitting = false;
        downloadTimers.forEach((timer) => window.clearTimeout(timer));
        downloadTimers.clear();
        announce('');
    });
}
