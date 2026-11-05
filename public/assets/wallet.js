(() => {
    const form = document.querySelector('[data-wallet-filters]');
    if (!form) return;
    document.documentElement.classList.add('wl-enhanced');
    form.addEventListener('change', () => form.requestSubmit());
    form.addEventListener('submit', () => {
        form.setAttribute('aria-busy', 'true');
        form.querySelector('button').textContent = 'Loading?';
    });
    window.addEventListener('pageshow', () => {
        form.removeAttribute('aria-busy');
        form.querySelector('button').textContent = 'Apply';
    });
})();
