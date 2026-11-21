// Renders machines through the real tile script and checks the numbers it prints.
// Run: node tests/tile.test.js
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

const src = fs.readFileSync(
  path.join(__dirname, '../src/vastai/usr/local/emhttp/plugins/vastai/javascript/vastai.js'),
  'utf8'
);

// Just enough jQuery for one refresh. Every selector returns the same element,
// and the table is the last thing the tile writes, so `html` ends up holding it.
let html = '';
let reply = null;
const el = { length: 1 };
for (const fn of ['text', 'prepend', 'addClass', 'removeClass', 'off', 'on']) el[fn] = () => el;
el.html = (h) => { html = h; return el; };
const $ = () => el;
$.ajax = (opts) => opts.success(reply);
const window = {};
new Function('$', 'window', 'document', src)($, window, { hidden: false });

function render(machine) {
  reply = {
    success: true,
    summary: {},
    machines: [{ id: 1, hostname: 'h', gpu_name: 'g', is_online: true, listed: true, ...machine }],
  };
  window.vastai_refresh();
  return {
    util: html.match(/vast-util-text[^>]*>([^<]*)</)[1],
    width: html.match(/width: (\d+)%/)[1],
    status: html.match(/vast-status-sub">([^<]*)</)[1],
  };
}

const rented = (num_gpus, occupancy_code, running_rentals) =>
  render({ num_gpus, occupancy_code, running_rentals, is_rented: true });

// One of eight GPUs rented is 13%, not 100%.
assert.deepStrictEqual(rented(8, 'DXXXXXXX', 1), { util: '13%', width: '13', status: 'On-Demand • 1 job' });
assert.strictEqual(rented(8, 'DDDDDDDD', 8).util, '100%');
assert.strictEqual(rented(2, 'IX', 1).util, '50%');
assert.strictEqual(rented(1, 'I', 1).util, '100%');

// A single-GPU host is fully rented however the API spells it.
assert.strictEqual(rented(1, '', 1).util, '100%');

// Split not reported: no percentage, bar still filled.
assert.deepStrictEqual(rented(8, 'D', 1), { util: '&ndash;', width: '100', status: 'On-Demand • 1 job' });
assert.strictEqual(rented(4, 'XXXX', 2).util, '&ndash;');
assert.strictEqual(rented(4, 'XXXX', 2).status, 'Active • 2 jobs');

// No rental count from the API: do not invent "1 job".
assert.strictEqual(rented(2, 'DX', 0).status, 'On-Demand');

// Idle and listed.
assert.strictEqual(render({ num_gpus: 8, occupancy_code: 'XXXXXXXX', is_rented: false }).util, '0%');

console.log('tile.test.js: ok');
