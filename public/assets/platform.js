(() => {
  const board = document.querySelector('.prediction-board');
  if (!board) return;

  document.querySelectorAll('[data-auto-submit]').forEach(form => {
    form.addEventListener('change', event => {
      if (!event.target.matches('input[type="radio"], select')) return;
      board.setAttribute('aria-busy', 'true');
      form.requestSubmit();
    });
  });

  // Keep a selected market visible in the mobile strip without moving the page.
  if (typeof window !== 'undefined' && window.matchMedia('(max-width: 700px)').matches) {
    const strip = document.querySelector('.prediction-toolbar .market-options');
    const selected = strip?.querySelector('input:checked')?.closest('label');
    if (strip && selected) window.requestAnimationFrame(() => {
      const bounds = strip.getBoundingClientRect();
      const active = selected.getBoundingClientRect();
      strip.scrollLeft += active.left - bounds.left - (strip.clientWidth - active.width) / 2;
    });
  }

  const stake = document.querySelector('#stake');
  const data = document.querySelector('#simulation-data');
  const output = document.querySelector('#simulation-result');
  if (!stake || !data || !output) return;
  const records = JSON.parse(data.textContent);
  const settings = JSON.parse(document.querySelector('#platform-settings').textContent);
  const limits = settings.stake;
  const stateLinks = document.querySelectorAll('a[href^="?"]');
  const formatMoney = value => new Intl.NumberFormat('en-NG', {
    style: 'currency', currency: settings.currency, maximumFractionDigits: 2,
  }).format(value / 100);

  const update = () => {
    const value = stake.value.trim();
    if (!/^\d{1,7}(?:\.\d{1,2})?$/.test(value) || Number(value) * 100 < limits.min_minor || Number(value) * 100 > limits.max_minor) {
      stake.setAttribute('aria-invalid', 'true');
      output.textContent = `Enter a stake from ${formatMoney(limits.min_minor)} to ${formatMoney(limits.max_minor)} (up to two decimal places).`;
      return;
    }
    stake.removeAttribute('aria-invalid');
    const minor = Math.round(Number(value) * 100);
    const points = [0];
    let net = 0;
    for (const row of records) {
      net += row.won ? Math.round(minor * (row.odds - 100) / 100) : -minor;
      points.push(net);
    }
    const totalStaked = minor * records.length;
    const roi = totalStaked ? (net / totalStaked * 100).toFixed(1) : '0.0';
    output.setAttribute('data-negative', String(net < 0));
    output.textContent = `Net result: ${formatMoney(net)} · ${roi}% ROI`;
    document.querySelectorAll('input[name="stake"]').forEach(input => {
      if (input !== stake) input.value = value;
    });
    stateLinks.forEach(link => {
      const url = new URL(link.href);
      if (url.searchParams.has('stake')) {
        url.searchParams.set('stake', value);
        link.href = url.pathname + url.search;
      }
    });
    const min = Math.min(...points);
    const span = Math.max(1, Math.max(...points) - min);
    const coordinates = points.map((point, index) =>
      `${(420 * index / Math.max(1, points.length - 1)).toFixed(2)},${(60 - 52 * (point - min) / span).toFixed(2)}`);
    document.querySelector('.performance-chart polyline').setAttribute('points', coordinates.join(' '));
    document.querySelector('.chart-area').setAttribute('d', `M0,68 L${coordinates.join(' L')} L420,68 Z`);
  };

  stake.addEventListener('input', update);
  document.querySelector('#simulation-form').addEventListener('submit', event => {
    event.preventDefault();
    update();
  });
  document.querySelectorAll('[data-stake-add]').forEach(button => {
    button.disabled = false;
    button.addEventListener('click', () => {
      const current = Math.max(0, Number(stake.value) || 0);
      stake.value = Math.min(limits.max_minor / 100, current + Number(button.dataset.stakeAdd)).toFixed(2);
      update();
    });
  });
})();
