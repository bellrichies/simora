'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

function element(extra = {}) {
  return { attributes: {}, events: {}, value: '', textContent: '',
    addEventListener(name, callback) { this.events[name] = callback; },
    setAttribute(name, value) { this.attributes[name] = value; },
    removeAttribute(name) { delete this.attributes[name]; }, ...extra };
}
const board = element();
const stake = element({ value: '500' });
const hiddenStake = element();
const output = element();
const chart = element();
const area = element();
const simulationForm = element();
const filter = element({ submitted: false, requestSubmit() { this.submitted = true; } });
const button = element({ disabled: true, dataset: { stakeAdd: '100' } });
const link = {
  current: 'http://localhost/sportybet?market=Under+2.5&stake=500&page=2',
  get href() { return this.current; },
  set href(value) { this.current = new URL(value, 'http://localhost').href; },
};
const selectors = {
  '.prediction-board': board, '#stake': stake, '#simulation-result': output,
  '#simulation-data': { textContent: JSON.stringify([{ won: true, odds: 172 }, { won: false, odds: 180 }]) },
  '#platform-settings': { textContent: JSON.stringify({ currency: 'NGN', stake: { min_minor: 1000, max_minor: 100000000 } }) },
  '#simulation-form': simulationForm, '.performance-chart polyline': chart, '.chart-area': area,
};
const document = {
  querySelector: selector => selectors[selector],
  querySelectorAll: selector => ({
    '[data-auto-submit]': [filter], 'input[name="stake"]': [stake, hiddenStake],
    'a[href^="?"]': [link], '[data-stake-add]': [button],
  }[selector] || []),
};
vm.runInNewContext(fs.readFileSync('public/assets/platform.js', 'utf8'), { document, Intl, URL, console });
assert.equal(button.disabled, false);
stake.events.input();
assert.match(output.textContent, /140\.00/);
assert.match(output.textContent, /-14\.0%/);
assert.equal(output.attributes['data-negative'], 'true');
assert.equal(hiddenStake.value, '500');
assert.ok(chart.attributes.points && area.attributes.d);
button.events.click();
assert.equal(stake.value, '600.00');
assert.match(output.textContent, /168\.00/);
assert.equal(new URL(link.href, 'http://localhost').searchParams.get('stake'), '600.00');
stake.value = '-5';
stake.events.input();
assert.equal(stake.attributes['aria-invalid'], 'true');
assert.match(output.textContent, /Enter a stake/);
stake.value = '5';
stake.events.input();
assert.equal(stake.attributes['aria-invalid'], 'true', 'Configured minimum is respected');
stake.value = '1000000';
button.events.click();
assert.equal(stake.value, '1000000.00');
assert.equal(new URL(link.href).searchParams.get('stake'), '1000000.00');
filter.events.change({ target: { matches: () => true } });
assert.equal(filter.submitted, true);
assert.equal(board.attributes['aria-busy'], 'true');
console.log('Platform interaction tests passed');
