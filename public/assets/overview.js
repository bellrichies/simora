(() => {
  const page = document.querySelector('[data-overview]');
  if (!page) return;
  page.classList.add('ov-enhanced');
  page.querySelectorAll('select').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
})();
