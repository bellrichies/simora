document.documentElement.classList.add('pl-enhanced');

// Keep the overview in sync with native GET forms and preserve their no-JS fallback.
document.querySelectorAll('[data-pl-league-form], .pl-filters, .pl-history-actions form').forEach(form => {
    form.addEventListener('change', () => {
        form.setAttribute('aria-busy', 'true');
        form.requestSubmit();
    });
});

document.querySelector('[data-pl-export]')?.addEventListener('click', () => {
    const rows = [...document.querySelectorAll('[data-pl-table] tr')];
    const csv = rows.map(row => [...row.cells].map(cell => '"' + cell.innerText.trim().replace(/^([=+@-])/, "'$1").replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\ufeff', csv], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = 'settled-predictions.csv';
    link.click();
    URL.revokeObjectURL(url);
    document.querySelector('[data-pl-feedback]').textContent = 'Current results page exported.';
});
