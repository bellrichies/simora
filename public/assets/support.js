(() => {
    const page = document.querySelector('[data-support-page]');
    if (!page) return;
    document.documentElement.classList.add('sp-enhanced');
    const tickets = page.querySelector('[data-ticket-filters]');
    tickets.addEventListener('change', () => tickets.requestSubmit());
    for (const form of page.querySelectorAll('form')) {
        form.addEventListener('submit', () => form.setAttribute('aria-busy', 'true'));
        window.addEventListener('pageshow', () => form.removeAttribute('aria-busy'));
    }
})();
