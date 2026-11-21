// Loads the real tile script with a stubbed jQuery and checks what it prints,
// what it requests and when it polls. Run: node tests/tile.test.js
'use strict';

const { test } = require('node:test');
const assert = require('node:assert');
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(
  path.join(__dirname, '../src/vastai/usr/local/emhttp/plugins/vastai/javascript/vastai.js'),
  'utf8'
);
// What the endpoint really sends for tests/fixtures/machines.json. Written by
// endpoint.test.php, so this file cannot drift from the PHP.
const payload = JSON.parse(fs.readFileSync(path.join(__dirname, 'fixtures/payload.json'), 'utf8'));

// A fresh copy of the tile per call. Each selector gets its own element, ajax
// calls are queued for the test to answer, and timers are recorded, not run.
function load({ interval = 30, hidden = false } = {}) {
  const t = { els: {}, ajax: [], timers: [], listeners: {}, window: {} };
  t.document = { hidden, addEventListener: (ev, fn) => { t.listeners[ev] = fn; } };
  const make = () => {
    const el = { length: 1, out: '', writes: 0, cls: new Set(), handlers: {}, value: '', visible: true };
    el.html = (h) => { el.out = h; el.writes++; return el; };
    el.text = (s) => { el.out = String(s); return el; };
    el.addClass = (c) => { el.cls.add(c); return el; };
    el.removeClass = (c) => { el.cls.delete(c); return el; };
    el.on = (ev, a, b) => { el.handlers[b ? `${ev} ${a}` : ev] = b || a; return el; };
    el.data = () => interval;
    el.val = () => el.value;
    el.is = () => el.visible;
    return el;
  };
  let ready = () => {};
  const $ = (sel) => {
    if (typeof sel === 'function') { ready = sel; return undefined; }
    if (typeof sel !== 'string') return sel;
    return (t.els[sel] ??= make());
  };
  $.ajax = (opts) => t.ajax.push(opts);
  const setInterval = (fn, ms) => t.timers.push({ fn, ms });
  const clearInterval = (id) => { t.timers[id - 1] = null; };
  new Function('$', 'window', 'document', 'setInterval', 'clearInterval', src)(
    $, t.window, t.document, setInterval, clearInterval
  );
  ready();
  t.el = (sel) => $(sel);
  t.body = () => $('#vast_widget_container').out;
  t.subtitle = () => $('#vast_subtitle').out;
  t.live = () => t.timers.filter(Boolean);
  t.fire = (sel, ev) => $(sel).handlers[ev].call($(sel), { preventDefault() {} });
  // A click on a link the tile drew inside its body.
  t.click = (action) => $('#vast_widget_container').handlers['click [data-vast]']
    .call({ getAttribute: () => action }, { preventDefault() {} });
  return t;
}

const machine = (m) => ({ id: 1, hostname: 'h', gpu_name: 'g', num_gpus: 1, state: 'listed', gpus_rented: 0, ...m });
const fleet = (machines, summary = {}, more = {}) => ({
  success: true, timestamp: 1, summary: { total_machines: machines.length, online_machines: machines.length, ...summary }, machines, ...more,
});

// Loads the tile and answers its first request with `reply`.
function show(reply, opts) {
  const t = load(opts);
  t.ajax[0].success(reply);
  return t;
}

function parse(html) {
  const pick = (re) => (html.match(re) || [null, null])[1];
  return {
    util: pick(/vast-util-text[^>]*>([^<]*)</),
    width: pick(/width: (\d+)%/),
    fill: pick(/vast-util-fill (fill-\w+)/),
    colour: pick(/vast-status-indicator (\S+)"/),
    status: pick(/vast-status-dot"><\/span>\s*(\w+)/),
    sub: pick(/vast-status-sub">([^<]*)</),
    rate: pick(/vast-rate-val[^>]*>([^<]*)</),
    rateSub: pick(/vast-rate-sub">([^<]*)</),
    html,
  };
}
const row = (m) => parse(show(fleet([machine(m)])).body());
const rented = (num_gpus, gpus_rented, running_rentals, more) =>
  row({ num_gpus, gpus_rented, running_rentals, state: 'rented', rental_type: 'on_demand', ...more });

const XSS = '<img src=x onerror=alert(1)>';

// ---------------------------------------------------------------- utilization

test('utilization is the share of GPUs rented', () => {
  const r = rented(8, 1, 1);
  assert.deepStrictEqual([r.util, r.width, r.sub], ['13%', '13', 'On-Demand • 1 job']);
  assert.strictEqual(rented(8, 8, 8).util, '100%');
  assert.strictEqual(rented(2, 1, 1).util, '50%');
  assert.strictEqual(rented(1, 1, 1).util, '100%');
  assert.strictEqual(row({ num_gpus: 8 }).util, '0%');
});

test('an unknown split prints a dash, not a percentage', () => {
  const r = rented(8, null, 1);
  assert.deepStrictEqual([r.util, r.width], ['&ndash;', '100']);
  assert.match(r.html, /title="Rented\. The API did not report how many GPUs\."/);
});

test('a job count is printed only when the API gave one', () => {
  assert.strictEqual(rented(4, null, 2, { rental_type: null }).sub, 'Active • 2 jobs');
  assert.strictEqual(rented(2, 1, 0).sub, 'On-Demand');
});

test('label and bar colour follow the rental type', () => {
  assert.deepStrictEqual([rented(2, 2, 2).sub, rented(2, 2, 2).fill], ['On-Demand • 2 jobs', 'fill-green']);
  const i = rented(2, 1, 1, { rental_type: 'interruptible' });
  assert.deepStrictEqual([i.sub, i.fill], ['Interruptible • 1 job', 'fill-amber']);
  assert.strictEqual(rented(1, 1, 1, { rental_type: 'reserved' }).sub, 'Reserved • 1 job');
});

// ---------------------------------------------------------------------- money

test('a rented row shows what the machine earns', () => {
  const r = rented(8, 8, 1, { earn_hour: 1.25, earn_day: 30, listed_gpu_cost: 0.5, listed: true });
  assert.deepStrictEqual([r.rate, r.rateSub], ['+$1.250/hr', 'Est. $30.00/day']);
});

test('a partly rented row also shows the ask for the GPUs still listed', () => {
  const r = rented(8, 2, 1, { earn_hour: 1.25, earn_day: 30, listed_gpu_cost: 0.5, listed: true });
  assert.match(r.rateSub, /^Est\. \$30\.00\/day &middot; ask \$0\.500\/hr\/GPU$/);
});

test('a listed row shows the machine ask the endpoint computed', () => {
  const r = row({ num_gpus: 8, ask_hour: 4, min_bid_hour: 2, listed_gpu_cost: 0.5 });
  assert.strictEqual(r.rate, '$4.000/hr');
  assert.match(r.rateSub, /^Min: \$2\.000\/hr &middot; \$0\.500\/hr\/GPU$/);
});

test('header totals come from the summary', () => {
  const t = show(fleet([machine({}), machine({ id: 2 })],
    { total_earn_hour: 2.5, total_earn_day: 60, account_balance: 12.3, rented_machines: 1 }));
  assert.match(t.body(), /\+\$2\.500\/hr<\/span><\/strong> \(Est\. \$60\.00\/day\)/);
  assert.match(t.body(), /Balance: <strong>\$12\.30</);
  assert.strictEqual(t.subtitle(), 'Status: ONLINE • 1 of 2 Rented');
});

test('an unknown balance is hidden, and a known zero is shown', () => {
  assert.ok(!show(fleet([machine({})], { account_balance: null })).body().includes('Balance'));
  assert.match(show(fleet([machine({})], { account_balance: 0 })).body(), /Balance: <strong>\$0\.00</);
});

test('a vanishing earnings figure reads as idle', () => {
  assert.match(show(fleet([machine({})], { total_earn_hour: 8e-55 })).body(), /\$0\.00\/hr<\/strong> \(Idle\)/);
});

// --------------------------------------------------------------------- states

test('each machine state has its label and its theme colour', () => {
  const of = (state) => { const r = row({ state }); return [r.status, r.colour, r.sub]; };
  assert.deepStrictEqual(of('offline'), ['offline', 'red-text', 'Unreachable']);
  assert.deepStrictEqual(of('listed'), ['listed', 'blue-text', 'Ready for rent']);
  assert.deepStrictEqual(of('unlisted'), ['unlisted', 'grey-text', 'Not listed']);
  assert.deepStrictEqual(row({ state: 'rented', gpus_rented: 1 }).status, 'active');
  assert.strictEqual(row({ state: 'unlisted' }).rate, '$0.00/hr');
  assert.strictEqual(row({ state: 'made-up' }).status, 'unlisted');
});

test('an offline host shows no live earnings and no utilization', () => {
  const r = row({ state: 'offline', gpus_rented: 0, earn_hour: 1, rental_type: null, running_rentals: 1 });
  assert.deepStrictEqual([r.status, r.rate, r.util, r.width], ['offline', '$0.00/hr', '0%', '0']);
});

test('the header reports the hosts, not that the API answered', () => {
  const sub = (online, total) => show(fleet(Array(total).fill(machine({})), { online_machines: online })).subtitle();
  assert.match(sub(2, 2), /^Status: ONLINE •/);
  assert.match(sub(0, 2), /^Status: OFFLINE •/);
  assert.match(sub(1, 2), /^Status: 1\/2 ONLINE •/);
});

test('an account with no machines', () => {
  const t = show(fleet([]));
  assert.match(t.body(), /No host machines found/);
  assert.strictEqual(t.subtitle(), 'No machines');
});

test('not configured: says so, links to Settings and stops polling', () => {
  const t = show({ success: false, code: 'not_configured', error: `no key ${XSS}` });
  assert.strictEqual(t.subtitle(), 'Not Configured');
  assert.match(t.body(), /href="\/Settings\/VastAISettings"/);
  assert.ok(!t.body().includes('<img'));
  assert.strictEqual(t.live().length, 0);
});

test('a rejected key links to Settings and offers no retry', () => {
  const t = show({ success: false, code: 'auth', error: 'Invalid user key (HTTP 404)' });
  assert.strictEqual(t.subtitle(), 'API Key Rejected');
  assert.match(t.body(), /Invalid user key/);
  assert.match(t.body(), /href="\/Settings\/VastAISettings"/);
  assert.ok(!t.body().includes('data-vast="retry"'));
});

test('an outage is shown escaped, with a retry that forces a fetch', () => {
  const t = show({ success: false, code: 'upstream', error: `HTTP 500 ${XSS}` });
  assert.strictEqual(t.subtitle(), 'Vast.ai Unreachable');
  assert.match(t.body(), /HTTP 500 &lt;img/);
  assert.ok(!t.body().includes('<img'));
  t.click('retry');
  assert.match(t.ajax[1].url, /\?force=1$/);
});

test('a failed request to this server', () => {
  const t = load();
  t.ajax[0].error({}, 'timeout', '');
  assert.strictEqual(t.subtitle(), 'Error');
  assert.match(t.body(), /on this server \(timeout\)/);
  assert.ok(!t.el('#vast_refresh_icon').cls.has('spinning'));
  assert.strictEqual(t.live().length, 1);
});

test('the login page in place of JSON stops polling', () => {
  const t = load();
  t.ajax[0].error({}, 'parsererror', '');
  assert.strictEqual(t.subtitle(), 'Signed Out');
  assert.strictEqual(t.live().length, 0);
});

test('stale data is labelled STALE with its age and the warning', () => {
  const t = show(fleet([machine({})], {}, { stale: true, cache_age: 600, warning: `down ${XSS}` }));
  assert.match(t.subtitle(), /STALE.*data 10m old$/);
  assert.ok(!t.subtitle().includes('ONLINE'));
  assert.match(t.body(), /vast-warn orange-text">down &lt;img/);
});

test('cached data says how old it is', () => {
  const t = show(fleet([machine({})], {}, { cached: true, cache_age: 12 }));
  assert.match(t.subtitle(), /ONLINE .* cached 12s ago$/);
});

// ------------------------------------------------------------------- escaping

test('string fields from the API are escaped', () => {
  const t = show(fleet([machine({ id: XSS, hostname: XSS, gpu_name: XSS, cpu_name: XSS, verification: XSS })]));
  t.click('details');
  assert.ok(!t.body().includes('<img'), t.body());
});

// The endpoint casts these. The tile checks them again, so a field of the
// wrong type can never become markup whatever sent it.
test('numeric fields are never printed as markup', () => {
  const leaks = [];
  const fields = ['num_gpus', 'gpus_rented', 'running_rentals', 'gpu_ram_gb', 'gpu_temp', 'reliability',
    'earn_hour', 'earn_day', 'ask_hour', 'min_bid_hour', 'listed_gpu_cost', 'cpu_cores', 'cpu_ram_gb',
    'disk_space_gb', 'avail_disk_gb', 'inet_down_mbps', 'inet_up_mbps'];
  for (const state of ['rented', 'listed']) {
    for (const f of fields) {
      const t = show(fleet([machine({ state, listed: true, num_gpus: 2, cpu_name: 'c', disk_space_gb: 1, inet_down_mbps: 1, inet_up_mbps: 1, [f]: XSS })]));
      t.click('details');
      if (t.body().includes('<img')) leaks.push(`${state}.${f}`);
    }
  }
  for (const f of ['rented_machines', 'total_machines', 'online_machines', 'total_earn_hour', 'total_earn_day', 'account_balance']) {
    const t = show(fleet([machine({})], { [f]: XSS }));
    if ((t.subtitle() + t.body()).includes('<img')) leaks.push(`summary.${f}`);
  }
  for (const f of ['cache_age', 'timestamp']) {
    const t = show(fleet([machine({})], {}, { cached: true, stale: f === 'cache_age', [f]: XSS }));
    if ((t.subtitle() + t.body()).includes('<img')) leaks.push(f);
  }
  assert.deepStrictEqual(leaks, []);
});

// -------------------------------------------------------------------- polling

test('the first load asks once, without force, and waits longer than the backend can take', () => {
  const t = load();
  assert.strictEqual(t.ajax.length, 1);
  assert.strictEqual(t.ajax[0].url, '/plugins/vastai/include/getvaststatus.php');
  assert.strictEqual(t.ajax[0].type, 'GET');
  assert.ok(t.ajax[0].timeout > 14000); // 9 s machines + 5 s balance
  assert.ok(t.el('#vast_refresh_icon').cls.has('spinning'));
});

test('interval 0 means off', () => {
  assert.strictEqual(load({ interval: 0 }).live().length, 0);
});

test('a positive interval polls at that interval', () => {
  const t = load({ interval: 30 });
  assert.deepStrictEqual(t.live().map((x) => x.ms), [30000]);
  t.ajax[0].success(fleet([]));
  t.live()[0].fn();
  assert.strictEqual(t.ajax.length, 2);
  assert.ok(!t.ajax[1].url.includes('force'));
});

test('a hidden tab does not poll, and refreshes when it comes back', () => {
  const t = load({ hidden: true });
  t.ajax[0].success(fleet([]));
  t.live()[0].fn();
  assert.strictEqual(t.ajax.length, 1);
  t.document.hidden = false;
  t.listeners.visibilitychange();
  assert.strictEqual(t.ajax.length, 2);
});

test('with polling off, coming back to the tab fetches nothing', () => {
  const t = load({ interval: 0 });
  t.ajax[0].success(fleet([]));
  t.listeners.visibilitychange();
  assert.strictEqual(t.ajax.length, 1);
});

test('a closed tile does not poll', () => {
  const t = show(fleet([]));
  t.el('#vast_tile').visible = false;
  t.live()[0].fn();
  assert.strictEqual(t.ajax.length, 1);
});

test('a tick during a request does not start a second one', () => {
  const t = load();
  t.live()[0].fn();
  assert.strictEqual(t.ajax.length, 1);
});

test('polling resumes after a failed request', () => {
  const t = load();
  t.ajax[0].error({}, 'error', '');
  t.live()[0].fn();
  assert.strictEqual(t.ajax.length, 2);
});

test('the interval select replaces the timer, and off clears it', () => {
  const t = load({ interval: 30 });
  t.el('#vast_int').value = '60';
  t.fire('#vast_int', 'change');
  assert.deepStrictEqual(t.live().map((x) => x.ms), [60000]);
  t.el('#vast_int').value = '0';
  t.fire('#vast_int', 'change');
  assert.strictEqual(t.live().length, 0);
});

test('the refresh button forces a refresh', () => {
  const t = show(fleet([]));
  t.fire('#vast_btn_refresh', 'click');
  assert.match(t.ajax[1].url, /\?force=1$/);
});

test('show details redraws without a request', () => {
  const t = show(fleet([machine({ cpu_name: 'EPYC', cpu_cores: 64, cpu_ram_gb: 128 })]));
  assert.ok(!t.body().includes('vast-details-row'));
  t.click('details');
  assert.match(t.body(), /CPU: <strong>EPYC \(64C\)/);
  assert.match(t.body(), /fa-microchip"><\/i> RAM: <strong>128 GB/);
  assert.strictEqual(t.ajax.length, 1);
});

test('a poll that returns the data already on screen leaves the table alone', () => {
  const t = show(fleet([machine({})]));
  const container = t.el('#vast_widget_container');
  const before = container.writes;
  t.live()[0].fn();
  t.ajax[1].success(fleet([machine({})], {}, { cached: true, cache_age: 9 }));
  assert.strictEqual(container.writes, before);
  assert.match(t.subtitle(), /cached 9s ago$/);
  t.live()[0].fn();
  t.ajax[2].success({ ...fleet([machine({})]), timestamp: 2 });
  assert.strictEqual(container.writes, before + 1);
});

test('after an error the same data is drawn again', () => {
  const t = show(fleet([machine({})]));
  t.live()[0].fn();
  t.ajax[1].error({}, 'timeout', '');
  t.live()[0].fn();
  t.ajax[2].success(fleet([machine({})]));
  assert.match(t.body(), /vast-table/);
});

// ------------------------------------------------- the endpoint's own answer

test('the pinned endpoint answer renders, one row per machine', () => {
  const t = show(payload);
  const rows = t.body().split('<tr class="vast-row">').slice(1).map(parse);
  assert.strictEqual(rows.length, payload.machines.length);
  const by = Object.fromEntries(payload.machines.map((m, i) => [m.hostname, rows[i]]));
  assert.deepStrictEqual([by['eight-partly'].util, by['eight-partly'].sub], ['25%', 'On-Demand • 2 jobs']);
  assert.deepStrictEqual([by['letters-only'].util, by['letters-only'].sub], ['25%', 'Reserved']);
  assert.deepStrictEqual([by['interruptible'].util, by['interruptible'].fill], ['100%', 'fill-amber']);
  assert.strictEqual(by['counter-only'].util, '&ndash;');
  assert.strictEqual(by['offline-rented'].status, 'offline');
  assert.strictEqual(by['four-idle'].rate, '$2.000/hr');
  assert.strictEqual(by['unlisted-never-rented'].rate, '$0.00/hr');
  assert.strictEqual(t.subtitle(), 'Status: 7/8 ONLINE • 4 of 8 Rented');
  assert.match(t.body(), /\+\$2\.222\/hr<\/span><\/strong> \(Est\. \$53\.71\/day\) \| Balance: <strong>\$5\.13</);
});
