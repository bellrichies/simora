'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function node(tag = 'div') {
  return { tag, children: [], attributes: {}, events: {}, textContent: '',
    append(child) { if (child.tag === 'fragment') this.children.push(...child.children); else this.children.push(child); },
    addEventListener(name, callback) { this.events[name] = callback; },
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; },
    focus() { this.focused = true; } };
}
const rows = node('tbody');
rows.children = Array.from({ length: 6 }, () => node('tr'));
const button = node('button');
const status = node();
const show = { value: '12' };
const detailsDialog = node('dialog');
const detailsTitle = node('h2');
const detailsFields = ['week', 'pick', 'odds', 'result', 'rate', 'status', 'kickoff'].map(key =>
  Object.assign(node('dd'), { dataset: { historyField: key } }));
const initialInfoButton = { disabled: true };
detailsDialog.querySelector = () => detailsTitle;
detailsDialog.querySelectorAll = () => detailsFields;
const form = Object.assign(node('form'), {
  dataset: { shown: '6', nextPage: '2', endpoint: '/api/platforms/sportybet/predictions' },
  querySelector(selector) { return selector === 'button' ? button : show; },
});
const document = {
  querySelector: selector => ({ '#history-prediction-dialog': detailsDialog, '[data-history-load]': form, '#prediction-history-rows': rows, '#history-load-status': status }[selector]),
  querySelectorAll: () => [initialInfoButton], createElementNS: (_namespace, tag) => node(tag),
  createElement: tag => node(tag), createDocumentFragment: () => node('fragment'),
};
class FormData {
  constructor() { this.values = [['week', 'W29'], ['market', 'Under 2.5'], ['status', 'Won'], ['stake', '500'], ['show', show.value]]; }
  [Symbol.iterator]() { return this.values[Symbol.iterator](); }
  forEach(callback) { this.values.forEach(([key, value]) => callback(value, key)); }
}
let calls = 0;
let lastUrl;
let request;
const fetch = url => { calls++; lastUrl = url; return request(); };
const window = { location: { origin: 'http://localhost', href: 'http://localhost/sportybet?market=Under+2.5' },
  history: { replaceState(_state, _title, url) { window.location.href = url.href; } } };
const record = { week: 'W29', home: 'Example', away: 'United', pick: 'Under 2.5', odds_hundredths: 172, home_score: 1, away_score: 0, status: 'Won', home_code: 'EXA', away_code: 'UTD', home_kit_id: 'gold', away_kit_id: 'blue', historical_win_rate: 52, kickoff_at: '2025-03-11T18:00:00Z', kickoff_label: 'Tue 11 Mar \u00b7 19:00', kickoff_date: '2025-03-11', kickoff_date_label: 'Tue, 11 Mar 2025', kickoff_time_label: '19:00' };
const batch = (page, next, total) => ({ ok: true, json: async () => ({ data: Array.from({ length: 6 }, () => ({ ...record })),
  error: null, meta: { page, next_page: next, total } }) });
vm.runInNewContext(fs.readFileSync('public/assets/history.js', 'utf8'), {
  document, FormData, URL, URLSearchParams, window, fetch, AbortController, setTimeout, clearTimeout,
});
assert.equal(initialInfoButton.disabled, false);
detailsDialog.events['dialog:prepare']({ detail: { trigger: { dataset: { historyInfo: JSON.stringify({ match: 'Example vs United', pick: 'Under 2.5', rate: '52%' }) } } } });
assert.equal(detailsTitle.textContent, 'Example vs United');
assert.equal(detailsFields[1].textContent, 'Under 2.5');
assert.equal(detailsFields[4].textContent, '52%');
detailsDialog.events['dialog:prepare']({ detail: { trigger: { dataset: { historyInfo: 'null' } } } });
assert.equal(detailsTitle.textContent, 'Prediction details');
(async () => {
  let resolve;
  request = () => new Promise(done => { resolve = done; });
  const pending = form.events.submit({ preventDefault() {} });
  await form.events.submit({ preventDefault() {} });
  assert.equal(calls, 1, 'Block duplicate clicks');
  assert.equal(button.disabled, true);
  assert.equal(lastUrl.searchParams.get('page'), '2');
  assert.equal(lastUrl.searchParams.get('week'), 'W29');
  assert.equal(lastUrl.searchParams.get('status'), 'Won');
  assert.equal(lastUrl.searchParams.has('show'), false);
  resolve(batch(2, 3, 18));
  await pending;
  assert.equal(rows.children.length, 12);
  assert.equal(rows.children[6].attributes.role, 'row');
  const cells = rows.children[6].children;
  assert.deepEqual(cells.map(cell => cell.attributes['data-label']), ['Week', 'Date', 'Kickoff', 'Match', 'Pick', 'Odds', 'Result', 'Status']);
  const byLabel = Object.fromEntries(cells.map(cell => [cell.attributes['data-label'], cell]));
  assert.equal(byLabel.Date.children[0].textContent, 'Tue, 11 Mar 2025');
  assert.equal(byLabel.Date.children[0].attributes.datetime, '2025-03-11');
  assert.equal(byLabel.Kickoff.children[0].textContent, '19:00');
  const matchPreview = byLabel.Match.children[1];
  assert.equal(matchPreview.className, 'history-match-preview');
  assert.equal(matchPreview.children.length, 2, 'Preview contains kits and match details without a Pick badge');
  assert.equal(matchPreview.attributes.title, 'Example vs United');
  assert.equal(matchPreview.children[0].children[0].children[0].attributes.href, '/assets/kits/mini-shirts.svg#gold');
  const info = JSON.parse(matchPreview.children[1].children[0].children[2].attributes['data-history-info']);
  assert.equal(info.rate, '52%');
  assert.equal(info.odds, '1.72');
  assert.equal(info.kickoff, 'Tue, 11 Mar 2025 · 19:00 WAT');
  assert.equal(form.dataset.nextPage, '3');
  assert.equal(show.value, '18');
  assert.equal(new URL(window.location.href).searchParams.get('show'), '12');
  assert.match(status.textContent, /Showing 12 of 18/);
  request = async () => ({ ok: false });
  await form.events.submit({ preventDefault() {} });
  assert.equal(rows.children.length, 12, 'Failure must preserve rows');
  assert.equal(form.dataset.nextPage, '3', 'Failure must preserve cursor');
  assert.equal(button.disabled, false);
  assert.equal(button.textContent, 'Try again');
  request = async () => batch(3, null, 18);
  await form.events.submit({ preventDefault() {} });
  assert.equal(rows.children.length, 18);
  assert.equal(form.hidden, true);
  assert.equal(status.focused, true);
  assert.match(status.textContent, /All 18 predictions loaded/);
  assert.equal(rows.attributes['aria-busy'], undefined);
  console.log('Load-more interaction tests passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
