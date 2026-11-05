(() => {
  const page = document.querySelector('[data-billing-page]');
  if (!page) return;
  page.classList.add('pb-enhanced');
  const feedback = page.querySelector('[data-billing-feedback]');
  const announce = text => { if (feedback) feedback.textContent = text; };
  const read = key => { try { return localStorage.getItem(key); } catch { return null; } };
  const save = (key, value) => { try { localStorage.setItem(key, value); return true; } catch { return false; } };
  const reminder = page.querySelector('#billing-reminders');
  if (reminder) {
    reminder.checked = read('simora.billing.preview.reminders') !== 'off';
    reminder.addEventListener('change', () => announce(save('simora.billing.preview.reminders', reminder.checked ? 'on' : 'off') ? 'Preview preference saved on this device.' : 'Preference updated for this visit. Storage is unavailable.'));
  }
  const email = page.querySelector('[data-billing-email]');
  const emailInput = page.querySelector('#billing-email');
  const storedEmail = read('simora.billing.preview.email');
  if (email && emailInput && storedEmail && storedEmail.length <= 254) { email.textContent = storedEmail; emailInput.value = storedEmail; }
  page.querySelector('[data-billing-email-form]')?.addEventListener('submit', event => {
    event.preventDefault();
    if (!emailInput.reportValidity()) return;
    const value = emailInput.value.trim();
    email.textContent = value;
    const persisted = save('simora.billing.preview.email', value);
    page.querySelector('#billing-email-dialog').close();
    announce(persisted ? 'Preview invoice email saved on this device.' : 'Preview email updated for this visit.');
  });
  page.querySelector('#billing-plan-dialog')?.addEventListener('dialog:prepare', event => {
    const trigger = event.detail.trigger;
    page.querySelector('[data-selected-plan]').textContent = trigger.dataset.plan;
    page.querySelector('[data-selected-price]').textContent = trigger.dataset.price;
  });
  page.querySelectorAll('.pb-filters select').forEach(select => select.addEventListener('change', () => select.form.requestSubmit()));
  page.querySelector('[data-billing-export]')?.addEventListener('click', () => {
    const rows = [['Illustrative billing records — current page only'], ['Invoice', 'Date', 'Plan', 'Amount (NGN)', 'Status']];
    page.querySelectorAll('.pb-table tbody tr').forEach(row => {
      const cells = row.querySelectorAll('td');
      if (cells.length !== 5) return;
      rows.push([cells[0].querySelector('strong').textContent, cells[0].querySelector('small').textContent, cells[1].textContent.trim(), cells[2].textContent.replace(/[^\d.]/g, ''), cells[3].textContent.trim()]);
    });
    const csv = rows.map(row => row.map(value => '"' + String(value).replace(/"/g, '""') + '"').join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' }));
    const anchor = document.createElement('a');
    anchor.href = url; anchor.download = 'simora-billing-preview.csv'; anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    announce('Current page exported as illustrative billing records.');
  });
})();
