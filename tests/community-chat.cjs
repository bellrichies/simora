'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function element() {
  return { hidden:false, attributes:{}, events:{}, focused:false,
    addEventListener(name, fn) { this.events[name] = fn; },
    setAttribute(name, value) { this.attributes[name] = value; },
    focus() { this.focused = true; } };
}
const toggle = element(), panel = element(), close = element(), heading = element(), dialog = element();
panel.hidden = true;
const widget = { querySelector(selector) { return {
  '[data-chat-toggle]':toggle, '#community-chat-panel':panel,
  '[data-chat-close]':close, '#community-chat-title':heading
}[selector]; } };
const document = { events:{}, modalOpen:false,
  querySelector(selector) { return selector === '[data-community-chat]' ? widget : this.modalOpen ? dialog : null; },
  querySelectorAll() { return [dialog]; },
  addEventListener(name, fn) { this.events[name] = fn; } };
vm.runInNewContext(fs.readFileSync('public/assets/community-chat.js', 'utf8'), { document });
assert.equal(toggle.hidden, false);
toggle.events.click();
assert.equal(panel.hidden, false);
assert.equal(toggle.attributes['aria-expanded'], 'true');
assert.equal(heading.focused, true);
document.events.keydown({key:'Escape'});
assert.equal(panel.hidden, true);
assert.equal(toggle.focused, true);
toggle.events.click();
document.modalOpen = true;
document.events.keydown({key:'Escape'});
assert.equal(panel.hidden, false);
document.modalOpen = false;
dialog.events['dialog:prepare']();
assert.equal(panel.hidden, true);
toggle.events.click();
close.events.click();
assert.equal(panel.hidden, true);
console.log('Community chat widget tests passed');
