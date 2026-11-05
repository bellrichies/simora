(() => {
    const page = document.querySelector('[data-referrals-page]');
    if (!page) return;
    const form = page.querySelector('[data-referral-filters]');
    document.documentElement.classList.add('rf-enhanced');
    form.addEventListener('change', () => form.requestSubmit());
    form.addEventListener('submit', () => form.setAttribute('aria-busy', 'true'));
    window.addEventListener('pageshow', () => form.removeAttribute('aria-busy'));
    const button = page.querySelector('[data-referral-copy]');
    const input = page.querySelector('#referral-link');
    const feedback = page.querySelector('[data-referral-feedback]');
    button.hidden = false;
    button.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(input.value);
            feedback.textContent = 'Demo link copied.';
        } catch {
            input.focus();
            input.select();
            feedback.textContent = 'Select and copy the demo link from this field.';
        }
    });
})();
