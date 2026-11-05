const fs = require('fs');
const vm = require('vm');
const assert = require('assert');

const script = fs.readFileSync('public/assets/app.js', 'utf8');
const source = script.slice(script.indexOf('function updateCountdowns()'), script.indexOf('\nupdateCountdowns();'));
assert(source.startsWith('function updateCountdowns()'));
let now = Date.parse('2026-11-03T12:00:00Z');
const time = { dataset: {}, dateTime: '', textContent: '' };
const element = {
  dataset: { countdown: '2026-11-03T12:03:00Z', countdownInterval: '180' },
  attributes: {},
  closest: () => ({ querySelector: () => time }),
  setAttribute(name, value) { this.attributes[name] = value; },
  removeAttribute(name) { delete this.attributes[name]; }
};
class TestDate extends Date { static now() { return now; } }
const context = { document: { querySelectorAll: () => [element] }, Date: TestDate, Intl };
vm.runInNewContext(source + '\nthis.updateCountdowns = updateCountdowns;', context);
function check(instant, expected, closed) {
  now = Date.parse(instant);
  context.updateCountdowns();
  assert.strictEqual(element.textContent, expected);
  assert.strictEqual(element.attributes['data-countdown-closed'] === 'true', closed);
}
check('2026-11-03T12:00:10Z', 'Closed', true);
check('2026-11-03T12:00:30Z', '2m 30s', false);
check('2026-11-03T12:02:59Z', '0m 01s', false);
check('2026-11-03T12:03:00Z', 'Closed', true);
check('2026-11-03T12:03:29Z', 'Closed', true);
check('2026-11-03T12:03:30Z', '2m 30s', false);
assert.strictEqual(time.dateTime, '2026-11-03T12:06:00.000Z');
check('2026-11-03T12:12:10Z', 'Closed', true);
check('2026-11-03T12:12:30Z', '2m 30s', false);
delete element.dataset.countdownInterval;
check('2026-11-03T12:12:30Z', 'Closed', true);
element.dataset.countdownClosedLabel = 'Round started';
check('2026-11-03T12:12:30Z', 'Round started', true);
console.log('Recurring countdown tests passed');
