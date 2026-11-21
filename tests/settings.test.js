// Runs the Test Connection script from the real settings page with a stubbed
// jQuery. Run: node tests/settings.test.js
'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const page = fs.readFileSync(
  path.join(__dirname, '../src/vastai/usr/local/emhttp/plugins/vastai/VastAISettings.page'),
  'utf8'
);
const script = page.match(/<script>([\s\S]*?)<\/script>/)[1];

function load(typedKey) {
  const t = { els: {}, ajax: [] };
  const make = (label = '') => {
    const el = { out: label, cls: new Set(), props: {}, shown: false };
    el.html = (h) => (h === undefined ? el.out : ((el.out = h), el));
    // Stands in for the browser's own text escaping, which vastEsc relies on.
    el.text = (s) => (s === undefined ? el.out : ((el.out = String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;')), el));
    el.addClass = (c) => { c.split(' ').forEach((x) => el.cls.add(x)); return el; };
    el.removeClass = (c) => { c.split(' ').forEach((x) => el.cls.delete(x)); return el; };
    el.show = () => { el.shown = true; return el; };
    el.prop = (k, v) => { el.props[k] = v; return el; };
    el.val = () => typedKey;
    return el;
  };
  // The button starts with a label that is not the English literal, as it
  // would on a translated page.
  t.els['#vast_btn_test'] = make('Verbindung testen');
  const $ = (sel) => (sel.startsWith('<') ? make() : (t.els[sel] ??= make()));
  $.ajax = (opts) => t.ajax.push(opts);
  t.test = new Function('$', 'document', script + '; return testVastConnection;')($, {});
  t.box = () => $('#vast_test_box');
  t.btn = () => $('#vast_btn_test');
  return t;
}

const summary = (more) => ({ total_machines: 2, rented_machines: 1, total_earn_hour: 1.5, account_balance: 12.3, ...more });

test('with no key typed it asks for one and sends nothing', () => {
  const t = load('   ');
  t.test();
  assert.strictEqual(t.ajax.length, 0);
  assert.match(t.box().out, /No API Key Entered/);
});

test('the typed key is posted, trimmed, never put in the URL', () => {
  const t = load('  abc123  ');
  t.test();
  const req = t.ajax[0];
  assert.strictEqual(req.type, 'POST'); // the endpoint ignores test_key on GET
  assert.deepStrictEqual(req.data, { test_key: 'abc123' });
  assert.ok(req.url.startsWith('/plugins/vastai/include/getvaststatus.php') && !req.url.includes('abc123'));
  assert.ok(req.timeout > 14000);
  assert.strictEqual(t.btn().props.disabled, true);
});

test('success reports the fleet and gives the button its own label back', () => {
  const t = load('k');
  t.test();
  assert.strictEqual(t.btn().out, 'Testing…');
  t.ajax[0].success({ success: true, summary: summary() });
  assert.match(t.box().out, /Found 2 machine\(s\) \(1 currently rented\)\. Total earnings rate: \$1\.500\/hr\. Account balance: \$12\.30\./);
  assert.ok(t.box().cls.has('vast-test-success'));
  assert.strictEqual(t.btn().props.disabled, false);
  assert.strictEqual(t.btn().out, 'Verbindung testen');
});

test('a balance that was not fetched is not reported as $0.00', () => {
  const t = load('k');
  t.test();
  t.ajax[0].success({ success: true, summary: summary({ account_balance: null }) });
  assert.ok(!t.box().out.includes('$0.00'));
  assert.match(t.box().out, /Account balance: not available/);
});

test('a balance of zero is reported as zero', () => {
  const t = load('k');
  t.test();
  t.ajax[0].success({ success: true, summary: summary({ account_balance: 0 }) });
  assert.match(t.box().out, /Account balance: \$0\.00\./);
});

test('numbers of the wrong type are not printed as markup', () => {
  const t = load('k');
  t.test();
  t.ajax[0].success({ success: true, summary: summary({ total_machines: '<img src=x>', account_balance: '<img src=x>' }) });
  assert.ok(!t.box().out.includes('<img'));
});

test('a rejected key is named as such, and the API text is escaped', () => {
  const t = load('k');
  t.test();
  t.ajax[0].success({ success: false, code: 'auth', error: 'Invalid user key <img src=x onerror=alert(1)>' });
  assert.match(t.box().out, /Key Rejected:<\/strong> Invalid user key &lt;img/);
  assert.ok(!t.box().out.includes('<img'));
  assert.ok(t.box().cls.has('vast-test-error'));
});

test('a failed request is reported and re-enables the button', () => {
  const t = load('k');
  t.test();
  t.ajax[0].error({}, 'timeout', '');
  assert.match(t.box().out, /Request Failed:<\/strong> this server did not answer \(timeout\)/);
  assert.strictEqual(t.btn().props.disabled, false);
  assert.strictEqual(t.btn().out, 'Verbindung testen');
});
