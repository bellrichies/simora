(() => {
    const page = document.querySelector('[data-reviews-page]');
    if (!page) return;
    document.documentElement.classList.add('rv-enhanced');
    const filters = page.querySelector('[data-review-filters]');
    filters.addEventListener('change', () => filters.requestSubmit());
    filters.addEventListener('submit', () => filters.setAttribute('aria-busy', 'true'));
    window.addEventListener('pageshow', () => filters.removeAttribute('aria-busy'));
    const editor = page.querySelector('#review-draft');
    const form = editor.querySelector('form');
    const status = form.querySelector('[data-draft-status]');
    const key = 'simora-review-draft-v1';
    const save = form.querySelector('[data-draft-save]');
    const clear = form.querySelector('[data-draft-clear]');
    save.hidden = clear.hidden = false;
    form.querySelector('[data-draft-fields]').disabled = false;
    function openEditor() {
        if (location.hash === '#review-draft') editor.open = true;
    }
    openEditor();
    window.addEventListener('hashchange', openEditor);
    try {
        const draft = JSON.parse(sessionStorage.getItem(key) || 'null');
        if (draft && ['1', '2', '3', '4', '5'].includes(draft.rating)
            && typeof draft.title === 'string' && typeof draft.body === 'string') {
            form.elements.rating.value = draft.rating;
            form.elements.title.value = draft.title.slice(0, 120);
            form.elements.body.value = draft.body.slice(0, 2000);
            status.textContent = 'Your local draft has been restored.';
        }
    } catch { status.textContent = 'Draft storage is unavailable. You can still try the editor.'; }
    form.addEventListener('submit', event => {
        event.preventDefault();
        const title = form.elements.title;
        const body = form.elements.body;
        title.setCustomValidity(title.value.trim() ? '' : 'Add a review title.');
        body.setCustomValidity(body.value.trim().length >= 20 ? '' : 'Write at least 20 characters.');
        if (!form.reportValidity()) return;
        try {
            sessionStorage.setItem(key, JSON.stringify({rating: form.elements.rating.value, title: title.value.trim(), body: body.value.trim()}));
            status.textContent = 'Draft saved in this browser tab. Nothing has been published.';
        } catch { status.textContent = 'Could not save the draft. Keep this tab open to retain your text.'; }
    });
    form.addEventListener('input', event => event.target.setCustomValidity?.(''));
    clear.addEventListener('click', () => {
        try { sessionStorage.removeItem(key); }
        catch { status.textContent = 'Could not clear saved storage. Your draft remains on this device.'; return; }
        form.reset();
        form.elements.title.setCustomValidity('');
        form.elements.body.setCustomValidity('');
        status.textContent = 'Local draft cleared.';
    });
})();
