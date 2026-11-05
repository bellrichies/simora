(() => {
  const detailsDialog = document.querySelector('#history-prediction-dialog');
  if (detailsDialog) detailsDialog.addEventListener('dialog:prepare', event => {
    let info = {};
    try { const parsed = JSON.parse(event.detail.trigger.dataset.historyInfo || '{}'); info = parsed && typeof parsed === 'object' ? parsed : {}; } catch (_) { /* Use empty fields. */ }
    detailsDialog.querySelector('[data-history-title]').textContent = typeof info.match === 'string' ? info.match : 'Prediction details';
    detailsDialog.querySelectorAll('[data-history-field]').forEach(field => {
      const value = info[field.dataset.historyField];
      field.textContent = typeof value === 'string' ? value : 'Unavailable';
    });
  });
  document.querySelectorAll('.history-info-trigger[disabled]').forEach(button => { button.disabled = false; });
  const form = document.querySelector('[data-history-load]');
  if (!form) return;
  const button = form.querySelector('button');
  const rows = document.querySelector('#prediction-history-rows');
  const status = document.querySelector('#history-load-status');
  let shown = Number(form.dataset.shown);
  let loading = false;

  const make = (tag, className, text) => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  const kit = id => {
    const allowed = ['white', 'blue', 'red', 'claret', 'orange', 'gold', 'red-white', 'blue-white', 'red-black', 'red-blue'];
    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    svg.setAttribute('class', 'history-mini-kit');
    svg.setAttribute('viewBox', '0 0 24 26');
    const use = document.createElementNS('http://www.w3.org/2000/svg', 'use');
    use.setAttribute('href', `/assets/kits/mini-shirts.svg#${allowed.includes(id) ? id : 'white'}`);
    svg.append(use);
    return svg;
  };
  const createRow = record => {
    if (!record || !['week', 'home', 'away', 'pick', 'home_code', 'away_code',
      'home_kit_id', 'away_kit_id', 'kickoff_at', 'kickoff_label', 'kickoff_date',
      'kickoff_date_label', 'kickoff_time_label'].every(key => typeof record[key] === 'string')
      || !['odds_hundredths', 'home_score', 'away_score', 'historical_win_rate'].every(key => Number.isInteger(record[key]))
      || record.historical_win_rate < 0 || record.historical_win_rate > 100
      || !['Won', 'Lost'].includes(record.status)) throw new Error('Invalid prediction data');
    const row = make('tr');
    row.setAttribute('role', 'row');
    const info = {
      match: `${record.home} vs ${record.away}`, week: record.week.replace(/^W(\d+)$/, 'Week $1'),
      kickoff: `${record.kickoff_date_label} \u00b7 ${record.kickoff_time_label} WAT`, pick: record.pick,
      odds: (record.odds_hundredths / 100).toFixed(2), result: `${record.home_score} - ${record.away_score}`,
      rate: `${record.historical_win_rate}%`, status: record.status,
    };
    const cells = Object.fromEntries(['Week', 'Date', 'Kickoff', 'Match', 'Pick', 'Odds', 'Result', 'Status'].map(label => {
      const cell = make('td'); cell.setAttribute('role', 'cell'); cell.setAttribute('data-label', label);
      row.append(cell); return [label, cell];
    }));
    cells.Week.append(make('span', 'history-week-short', record.week));
    cells.Week.append(make('strong', 'history-week-label', info.week));
    const time = make('time', 'history-time', record.kickoff_label);
    time.setAttribute('datetime', record.kickoff_at);
    time.setAttribute('title', info.kickoff);
    cells.Week.append(time);
    cells.Match.append(make('span', 'history-match-full', info.match));
    const preview = make('div', 'history-match-preview');
    preview.setAttribute('title', info.match);
    const kits = make('span', 'history-mini-kits'); kits.setAttribute('aria-hidden', 'true');
    kits.append(kit(record.home_kit_id)); kits.append(kit(record.away_kit_id)); preview.append(kits);
    const content = make('div', 'history-mini-content');
    const line = make('div', 'history-mini-line');
    const codes = make('span', '', `${record.home_code} v ${record.away_code}`); codes.setAttribute('aria-hidden', 'true');
    line.append(codes); line.append(make('span', 'sr-only', `${info.match}, ${record.pick}`));
    const details = make('button', 'history-info-trigger');
    details.type = 'button'; details.setAttribute('data-dialog', 'history-prediction-dialog');
    details.setAttribute('data-history-info', JSON.stringify(info));
    details.setAttribute('aria-label', `Prediction details for ${info.match}`);
    const icon = make('i', 'bi bi-info-circle'); icon.setAttribute('aria-hidden', 'true'); details.append(icon);
    line.append(details); content.append(line);
    const facts = make('p', 'history-mini-facts', `${info.result} \u00b7 @ ${info.odds} \u00b7 ${info.rate}`);
    facts.setAttribute('aria-hidden', 'true'); content.append(facts);
    content.append(make('span', 'sr-only', `Result ${info.result}, odds ${info.odds}, historical win rate ${info.rate}`));
    preview.append(content); cells.Match.append(preview);
    const date = make('time', '', record.kickoff_date_label);
    date.setAttribute('datetime', record.kickoff_date);
    cells.Date.append(date);
    const kickoff = make('time', '', record.kickoff_time_label);
    kickoff.setAttribute('datetime', record.kickoff_at);
    kickoff.setAttribute('title', 'West Africa Time (Africa/Lagos)');
    cells.Kickoff.append(kickoff);
    cells.Pick.textContent = record.pick; cells.Odds.textContent = info.odds;
    cells.Result.textContent = `${record.home_score} \u2013 ${record.away_score}`;
    cells.Status.append(make('span', `result-badge ${record.status === 'Won' ? 'won' : 'lost'}`, record.status));
    return row;
  };

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (loading) return;
    loading = true;
    button.disabled = true;
    button.textContent = 'Loading...';
    rows.setAttribute('aria-busy', 'true');
    status.textContent = 'Loading more predictions...';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 15000);
    try {
      const page = Number(form.dataset.nextPage);
      const params = new URLSearchParams(new FormData(form));
      params.delete('show');
      params.set('page', String(page));
      const url = new URL(form.dataset.endpoint, window.location.origin);
      url.search = params.toString();
      const response = await fetch(url, { headers: { Accept: 'application/json' },
        credentials: 'same-origin', signal: controller.signal });
      if (!response.ok) throw new Error('Request failed');
      const result = await response.json();
      const meta = result.meta;
      if (result.error || !Array.isArray(result.data) || !meta || meta.page !== page
        || !Number.isInteger(meta.total) || meta.total < shown + result.data.length
        || !(meta.next_page === null || (Number.isInteger(meta.next_page) && meta.next_page > page))) {
        throw new Error('Invalid response');
      }
      const fragment = document.createDocumentFragment();
      result.data.forEach(record => fragment.append(createRow(record)));
      rows.append(fragment);
      shown += result.data.length;
      form.dataset.shown = String(shown);
      form.querySelector('[name="show"]').value = String(Math.min(shown + (meta.page_size || result.data.length), meta.total));
      form.dataset.nextPage = meta.next_page === null ? '' : String(meta.next_page);
      const finished = meta.next_page === null;
      status.textContent = finished ? `All ${shown} predictions loaded.` : `Showing ${shown} of ${meta.total} predictions`;
      form.hidden = finished;
      button.textContent = 'Load more';
      const current = new URL(window.location.href);
      new FormData(form).forEach((value, key) => current.searchParams.set(key, value));
      current.searchParams.set('show', String(shown));
      current.searchParams.delete('page');
      window.history.replaceState(null, '', current);
      if (finished) {
        status.tabIndex = -1;
        status.focus({ preventScroll: true });
      }
    } catch (error) {
      status.textContent = 'Could not load more predictions. Please try again.';
      button.textContent = 'Try again';
    } finally {
      clearTimeout(timeout);
      rows.removeAttribute('aria-busy');
      button.disabled = false;
      loading = false;
    }
  });
})();
