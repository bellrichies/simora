(() => {
 const page = document.querySelector('[data-predictions-hub]'); if (!page) return;
 page.classList.add('ph-enhanced');
 page.querySelectorAll('.ph-focus-form select,.ph-markets input').forEach(input => input.addEventListener('change', () => input.form.requestSubmit()));
 const form = page.querySelector('[data-simulation-form]');
 const rows = JSON.parse(document.getElementById('simulation-data')?.textContent || '[]');
 const money = n => '₦' + n.toLocaleString('en-NG', {maximumFractionDigits: 2});
 const set = (name, value) => { page.querySelector('[data-' + name + ']').textContent = value; };
 function run() {
  if (!form.reportValidity()) return;
  const data = new FormData(form), initial = Number(data.get('bankroll')), base = Number(data.get('stake')), nextOdds = Number(data.get('odds')), strategy = data.get('strategy');
  let balance = initial, peak = initial, drawdown = 0, loss = 0, previous = base, played = 0; const points = [0];
  const stakeFor = odds => strategy === 'flat' ? base : strategy === 'martingale' ? (loss > 0 ? previous * 2 : base) : (loss > 0 ? (loss + base * (odds - 1)) / (odds - 1) : base);
  for (const row of rows.slice(0, Number(data.get('count'))).reverse()) {
   if (balance < 0.01) break;
   const stake = Math.min(balance, stakeFor(row.odds)); previous = stake;
   const change = row.won ? stake * (row.odds - 1) : -stake;
   balance = Math.max(0, balance + change); loss = row.won ? Math.max(0, loss - change) : loss + stake;
   peak = Math.max(peak, balance); drawdown = Math.max(drawdown, peak ? (peak - balance) / peak * 100 : 0); points.push(balance - initial); played++;
  }
  const net = balance - initial; set('net', (net > 0 ? '+' : net < 0 ? '−' : '') + money(Math.abs(net))); set('ending', money(balance)); set('return', (net > 0 ? '+' : '') + (net / initial * 100).toFixed(1) + '%'); set('drawdown', drawdown.toFixed(1) + '%'); set('next', money(Math.min(balance, stakeFor(nextOdds)))); set('count', played + ' / ' + Math.min(rows.length, Number(data.get('count'))) + ' outcomes replayed'); set('strategy-label', strategy[0].toUpperCase() + strategy.slice(1));
  set('simulation-status', rows.length ? (balance < .01 ? 'Replay stopped: bankroll exhausted. Synthetic examples, not a forecast.' : 'Replay complete. Synthetic examples, not a forecast.') : 'No settled outcomes available for this selection.');
  const min = Math.min(...points), max = Math.max(...points), span = max - min || 1; const coords = points.map((n, i) => `${i * 600 / Math.max(1, points.length - 1)},${95 - (n - min) / span * 85}`); page.querySelector('[data-chart-line]').setAttribute('points', coords.join(' ')); page.querySelector('[data-chart-area]').setAttribute('d', 'M0,100 L' + coords.join(' L') + ' L600,100 Z');
 }
 form?.addEventListener('submit', e => { e.preventDefault(); run(); }); if (form) run();
 page.querySelector('[data-outcome]')?.addEventListener('change', e => page.querySelectorAll('[data-outcome-row]').forEach(row => { row.hidden = e.target.value !== 'all' && row.dataset.outcomeRow !== e.target.value; }));
 page.querySelector('[data-predictions-export]')?.addEventListener('click', () => {
  const data = [['Synthetic prediction results - visible recent outcomes'], ['Week', 'Kickoff (WAT)', 'Match', 'Market', 'Odds', 'Score', 'Outcome']]; page.querySelectorAll('[data-outcome-row]:not([hidden])').forEach(row => data.push([...row.cells].map(cell => cell.textContent.trim())));
  const quote = v => '"' + String(v).replace(/^[=+@-]/, "'$&").replace(/"/g, '""') + '"'; const url = URL.createObjectURL(new Blob(['\uFEFF' + data.map(row => row.map(quote).join(',')).join('\r\n')], {type:'text/csv;charset=utf-8'})); const link = document.createElement('a'); link.href = url; link.download = 'simora-sample-predictions.csv'; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); page.querySelector('[data-predictions-feedback]').textContent = 'Visible synthetic outcomes exported.';
 });
})();
