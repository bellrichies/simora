(() => {
    const page = document.querySelector('[data-settings-page]');
    if (!page) return;
    const config = JSON.parse(document.querySelector('#settings-preview-data').textContent);
    const key = 'simora-settings-preview-v1';
    const clone = value => JSON.parse(JSON.stringify(value));
    let model = clone(config.defaults);
    let storageAvailable = true;
    function validated(value) {
        const next = clone(config.defaults);
        if (!value || typeof value !== 'object') return next;
        if (typeof value.name === 'string' && value.name.trim() && Array.from(value.name.trim()).length <= 80) next.name = value.name.trim();
        if (typeof value.timezone === 'string' && Object.hasOwn(config.timezones, value.timezone)) next.timezone = value.timezone;
        if (config.platforms.includes(value.platform)) next.platform = value.platform;
        if (config.markets.includes(value.market)) next.market = value.market;
        for (const id of Object.keys(next.notifications)) {
            if (typeof value.notifications?.[id] === 'boolean') next.notifications[id] = value.notifications[id];
        }
        return next;
    }
    try { model = validated(JSON.parse(localStorage.getItem(key) || 'null')); }
    catch { storageAvailable = false; }
    const form = page.querySelector('[data-settings-form]');
    const feedback = page.querySelector('[data-settings-feedback]');
    const announce = message => { if (feedback) feedback.textContent = message; };
    function render() {
        page.querySelectorAll('[data-preview-name]').forEach(el => { el.textContent = model.name; });
        page.querySelectorAll('[data-preview-initial]').forEach(el => { el.textContent = Array.from(model.name)[0].toUpperCase(); });
        page.querySelector('[data-preview-timezone]').textContent = config.timezones[model.timezone];
        page.querySelector('[data-notification-count]').textContent = Object.values(model.notifications).filter(Boolean).length;
        const sample = page.querySelector('[data-example-time]');
        if (sample) sample.textContent = new Intl.DateTimeFormat('en-GB', {timeZone: model.timezone, dateStyle: 'medium', timeStyle: 'short'}).format(new Date(config.sampleTime));
        const workspace = page.querySelector('[data-preferred-workspace]');
        if (workspace) workspace.href = '/predictions?' + new URLSearchParams({focus: model.platform, market: model.market});
        if (!form) return;
        const section = form.dataset.settingsForm;
        if (section === 'profile') form.elements.name.value = model.name;
        if (section === 'preferences') for (const id of ['timezone', 'platform', 'market']) form.elements[id].value = model[id];
        if (section === 'notifications') for (const [id, enabled] of Object.entries(model.notifications)) form.elements[id].checked = enabled;
    }
    render();
    if (!storageAvailable) announce('Browser storage is unavailable. Changes can be tried for this visit only.');
    if (form) {
        form.querySelector('fieldset').disabled = false;
        form.addEventListener('input', event => {
            event.target.setCustomValidity?.('');
            announce('Unsaved changes. Save to apply your local preferences.');
        });
        form.addEventListener('submit', event => {
            event.preventDefault();
            const candidate = clone(model);
            const section = form.dataset.settingsForm;
            if (section === 'profile') {
                const input = form.elements.name;
                const name = input.value.trim();
                input.setCustomValidity(name && Array.from(name).length <= 80 ? '' : 'Enter a name between 1 and 80 characters.');
                if (!form.reportValidity()) return;
                candidate.name = name;
            }
            if (section === 'preferences') for (const id of ['timezone', 'platform', 'market']) candidate[id] = form.elements[id].value;
            if (section === 'notifications') for (const id of Object.keys(candidate.notifications)) candidate.notifications[id] = form.elements[id].checked;
            model = validated(candidate);
            try {
                localStorage.setItem(key, JSON.stringify(model));
                announce('Preview settings saved in this browser.');
            } catch { announce('Updated for this visit. Browser storage is unavailable, so changes could not be saved.'); }
            render();
        });
        form.querySelector('[data-settings-cancel]').addEventListener('click', () => {
            for (const field of form.querySelectorAll('input,select')) field.setCustomValidity('');
            render();
            announce('Unsaved changes discarded.');
        });
    }
    const reset = page.querySelector('[data-settings-reset]');
    if (reset) {
        reset.hidden = false;
        reset.addEventListener('click', () => {
            try { localStorage.removeItem(key); }
            catch { announce('Could not reset saved settings because browser storage is unavailable.'); return; }
            model = clone(config.defaults);
            render();
            announce('Settings preview reset to defaults.');
        });
    }
    const exportButton = page.querySelector('[data-settings-export]');
    if (exportButton) {
        exportButton.hidden = false;
        exportButton.addEventListener('click', () => {
            const blob = new Blob([JSON.stringify({preview: true, preferences: model}, null, 2)], {type: 'application/json'});
            const url = URL.createObjectURL(blob);
            const anchor = document.createElement('a');
            anchor.href = url;
            anchor.download = 'simora-preview-settings.json';
            anchor.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            announce('Preview settings exported. This is not an account data export.');
        });
    }
})();
