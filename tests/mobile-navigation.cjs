'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function classes() {
  const values = new Set();
  return { toggle(name, force) { const add = force === undefined ? !values.has(name) : force; add ? values.add(name) : values.delete(name); return add; }, contains(name) { return values.has(name); } };
}
let document;
function element() {
  return { events: {}, attributes: {}, classList: classes(),
    addEventListener(name, callback) { this.events[name] = callback; },
    setAttribute(name, value) { this.attributes[name] = value; },
    focus() { document.activeElement = this; }, getClientRects() { return [{}]; } };
}
const menu = element(), sidebar = element(), backdrop = element(), workspace = element(), close = element(), collapse = element();
const brand = element(), active = element(), signout = element();
const dialog = { opened: false, dispatchEvent(event) { this.prepared = event; }, showModal() { this.opened = true; } };
sidebar.querySelectorAll = () => [brand, close, active, signout];
sidebar.querySelector = () => active;
sidebar.contains = node => [brand, close, active, signout].includes(node);
let mobile = true;
const window = { events: {}, matchMedia: () => ({ matches: mobile }), addEventListener(name, callback) { this.events[name] = callback; } };
document = {
  body: { classList: classes() }, activeElement: menu, events: {},
  addEventListener(name, callback) { this.events[name] = callback; },
  querySelector: selector => ({ '[data-menu]': menu, '#sidebar': sidebar, '[data-close-menu]': backdrop,
    '.workspace': workspace, '[data-close-sidebar]': close, '[data-collapse]': collapse }[selector]),
  querySelectorAll: () => [],
  getElementById: () => dialog,
};
class CustomEvent { constructor(type, options) { this.type = type; this.detail = options.detail; } }
vm.runInNewContext(fs.readFileSync('public/assets/app.js', 'utf8'), { document, window, CustomEvent });
assert.equal(sidebar.inert, true);
menu.events.click();
assert.equal(sidebar.inert, false);
assert.equal(workspace.inert, true);
assert.equal(document.body.classList.contains('menu-open'), true);
assert.equal(document.activeElement, close);
brand.focus();
let prevented = false;
document.events.keydown({ key: 'Tab', shiftKey: true, preventDefault() { prevented = true; } });
assert.equal(prevented, true);
assert.equal(document.activeElement, signout);
document.events.keydown({ key: 'Tab', shiftKey: false, preventDefault() {} });
assert.equal(document.activeElement, brand);
document.events.keydown({ key: 'Escape' });
assert.equal(workspace.inert, false);
assert.equal(sidebar.inert, true);
assert.equal(document.activeElement, menu);
menu.events.click();
backdrop.events.click();
assert.equal(document.body.classList.contains('menu-open'), false);
menu.events.click();
close.events.click();
assert.equal(sidebar.classList.contains('open'), false);
menu.events.click();
mobile = false;
window.events.resize();
assert.equal(sidebar.inert, false);
assert.equal(workspace.inert, false);
assert.equal(document.activeElement, active);
const appendedTrigger = Object.assign(element(), { dataset: { dialog: 'history-prediction-dialog' } });
document.events.click({ target: { closest: () => appendedTrigger } });
assert.equal(dialog.opened, true, 'Dynamically appended info controls open the dialog');
assert.equal(dialog.prepared.type, 'dialog:prepare');
assert.equal(dialog.prepared.detail.trigger, appendedTrigger);
console.log('Mobile navigation tests passed');
