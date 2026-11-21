/**
 * Vast.ai Unraid Plugin - dashboard tile
 *
 * Polls the plugin's endpoint and draws its answer as a table in the tile.
 * The endpoint decides what state a machine is in and what it costs. This file
 * only draws that, so the two cannot disagree.
 */

(function () {
  'use strict';

  const ENDPOINT = '/plugins/vastai/include/getvaststatus.php';

  let pollTimer = null;
  let isFetching = false;
  let showHardwareDetails = false;
  let lastData = null;
  let lastRenderKey = null;

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  // Every number the endpoint sends goes through here before it is printed, so
  // a field of the wrong type can never reach the page as markup.
  function num(v) {
    const n = Number(v);
    return Number.isFinite(n) ? n : 0;
  }

  function formatMoney(amount, decimals = 2) {
    return '$' + num(amount).toFixed(decimals);
  }

  function formatRate(amount) {
    const n = num(amount);
    if (n < 0.00005) return '$0.00/hr';
    if (n < 0.01) return '$' + n.toFixed(4) + '/hr';
    return '$' + n.toFixed(3) + '/hr';
  }

  function formatAge(seconds) {
    const s = num(seconds);
    if (s < 90) return `${Math.round(s)}s`;
    if (s < 5400) return `${Math.round(s / 60)}m`;
    return `${Math.round(s / 3600)}h`;
  }

  // The text colours are Unraid's own classes, which read on light and dark
  // themes alike. state: [colour, label, line under the label, bar fill]
  const STATES = {
    offline:  ['red-text',   'offline',  'Unreachable',    'fill-gray'],
    rented:   ['green-text', 'active',   'Active',         'fill-green'],
    listed:   ['blue-text',  'listed',   'Ready for rent', 'fill-blue'],
    unlisted: ['grey-text',  'unlisted', 'Not listed',     'fill-gray'],
  };
  const RENTAL = { on_demand: 'On-Demand', reserved: 'Reserved', interruptible: 'Interruptible' };

  function renderMachineRow(m) {
    const state = STATES[m.state] ? m.state : 'unlisted';
    const [colour, label, idleSub, stateFill] = STATES[state];
    const rented = state === 'rented';
    const gpus = num(m.num_gpus) || 1;
    // null when the API did not say how many of the GPUs are rented.
    const busy = m.gpus_rented === null || m.gpus_rented === undefined ? null : num(m.gpus_rented);

    // Status
    let sub = idleSub;
    if (rented) {
      // Print a job count only when the API gave one.
      const jobs = num(m.running_rentals);
      sub = (RENTAL[m.rental_type] || 'Active') + (jobs > 0 ? ` • ${jobs} ${jobs === 1 ? 'job' : 'jobs'}` : '');
    }
    const statusHtml = `
      <span class="vast-status-indicator ${colour}"><span class="vast-status-dot"></span> ${label}</span>
      <div class="vast-status-sub">${sub}</div>
    `;

    // Rate. Always per machine: what it earns when rented, what it asks when listed.
    let rateHtml = `
      <span class="vast-rate-val vast-dim">$0.00/hr</span>
      <div class="vast-rate-sub">-</div>
    `;
    if (rented) {
      // On a partly rented host the other GPUs are still for rent.
      const ask = m.listed && busy !== null && busy < gpus && num(m.listed_gpu_cost) > 0
        ? ` &middot; ask ${formatRate(m.listed_gpu_cost)}/GPU` : '';
      rateHtml = `
        <span class="vast-rate-val green-text">+${formatRate(m.earn_hour)}</span>
        <div class="vast-rate-sub">Est. ${formatMoney(m.earn_day)}/day${ask}</div>
      `;
    } else if (state === 'listed') {
      rateHtml = `
        <span class="vast-rate-val">${formatRate(m.ask_hour)}</span>
        <div class="vast-rate-sub">Min: ${formatRate(m.min_bid_hour)}${gpus > 1 ? ` &middot; ${formatRate(m.listed_gpu_cost)}/GPU` : ''}</div>
      `;
    }

    // Temperature
    let tempHtml = '-';
    if (m.gpu_temp !== null && m.gpu_temp !== undefined) {
      const temp = num(m.gpu_temp);
      const tempClass = temp >= 80 ? 'red-text vast-temp-hot' : temp >= 65 ? 'orange-text' : 'green-text';
      tempHtml = `<span class="${tempClass}">${temp} °C</span>`;
    }

    // Reliability
    let reliabHtml = '-';
    if (m.reliability !== null && m.reliability !== undefined) {
      const rel = num(m.reliability);
      reliabHtml = rel >= 95
        ? `<span class="vast-smart green-text"><i class="fa fa-check"></i> ${rel}%</span>`
        : `<span class="vast-smart orange-text"><i class="fa fa-exclamation-triangle"></i> ${rel}%</span>`;
    }

    // Utilization: the share of this machine's GPUs that are rented. With an
    // unknown split the bar is filled, because something is rented, but no
    // number is printed.
    let utilPercent = 0;
    let utilText = '0%';
    let fill = stateFill;
    if (rented) {
      utilPercent = busy === null ? 100 : Math.min(100, Math.round(100 * busy / gpus));
      utilText = busy === null ? '&ndash;' : `${utilPercent}%`;
      if (m.rental_type === 'interruptible') fill = 'fill-amber';
    }
    const utilTitle = rented && busy === null ? ' title="Rented. The API did not report how many GPUs."' : '';
    const utilHtml = `
      <span class="vast-util-text"${utilTitle}>${utilText}</span>
      <div class="vast-util-bar">
        <div class="vast-util-fill ${fill}" style="width: ${utilPercent}%;"></div>
      </div>
    `;

    const ram = num(m.gpu_ram_gb);
    let html = `
      <tr class="vast-row">
        <td class="vast-col-device">
          <div class="vast-dev-name">
            <i class="fa fa-desktop vast-icon-host"></i>
            <span>${escapeHtml(m.hostname)}</span>
          </div>
          <div class="vast-dev-sub">#${escapeHtml(m.id)} • ${gpus}x ${escapeHtml(m.gpu_name)} ${ram ? `(${ram} GB)` : ''}</div>
        </td>
        <td class="vast-col-status">${statusHtml}</td>
        <td class="vast-col-rate">${rateHtml}</td>
        <td class="vast-col-temp">${tempHtml}</td>
        <td class="vast-col-smart">${reliabHtml}</td>
        <td class="vast-col-util">${utilHtml}</td>
      </tr>
    `;

    if (showHardwareDetails) {
      const details = [];
      const item = (icon, text) => details.push(`<div class="vast-details-item"><i class="fa ${icon}"></i> ${text}</div>`);
      if (m.cpu_name) {
        item('fa-server', `CPU: <strong>${escapeHtml(m.cpu_name)}${num(m.cpu_cores) ? ` (${num(m.cpu_cores)}C)` : ''}</strong>`);
      }
      if (num(m.cpu_ram_gb)) {
        item('fa-microchip', `RAM: <strong>${num(m.cpu_ram_gb)} GB</strong>`);
      }
      if (num(m.disk_space_gb)) {
        item('fa-hdd-o', `Disk: <strong>${num(m.disk_space_gb)} GB (${num(m.avail_disk_gb)} GB Free)</strong>`);
      }
      if (num(m.inet_down_mbps) && num(m.inet_up_mbps)) {
        item('fa-exchange', `Net: <strong>${num(m.inet_down_mbps)} ↓ / ${num(m.inet_up_mbps)} ↑ Mbps</strong>`);
      }
      if (m.verification) {
        item('fa-shield', `Verification: <strong>${escapeHtml(m.verification)}</strong>`);
      }
      html += `
        <tr class="vast-details-row">
          <td colspan="6">
            <div class="vast-details-grid">${details.join('')}</div>
          </td>
        </tr>
      `;
    }

    return html;
  }

  function renderTile(data) {
    const sum = data.summary || {};
    const machines = Array.isArray(data.machines) ? data.machines : [];
    const total = num(sum.total_machines);
    const online = sum.online_machines === undefined ? total : num(sum.online_machines);

    // The header says whether the hosts are online. That the API answered is
    // not the same thing, and stale data is never called online.
    let hosts = `${online}/${total} ONLINE`;
    if (online === total) hosts = 'ONLINE';
    else if (online === 0) hosts = 'OFFLINE';
    const rented = `${num(sum.rented_machines)} of ${total} Rented`;
    const $subtitle = $('#vast_subtitle');
    if (total === 0) {
      $subtitle.text('No machines');
    } else if (data.stale) {
      $subtitle.html(`<span class="vast-stale orange-text">Status: STALE</span> • ${rented} • data ${formatAge(data.cache_age)} old`);
    } else if (data.cached && num(data.cache_age) > 0) {
      $subtitle.html(`Status: ${hosts} • ${rented} • cached ${num(data.cache_age)}s ago`);
    } else {
      $subtitle.html(`Status: ${hosts} • ${rented}`);
    }

    // Most polls return the data that is already on screen. Leaving the table
    // alone keeps a text selection or a hover alive between polls.
    const key = [data.timestamp, showHardwareDetails, data.warning || '', machines.length].join('|');
    if (key === lastRenderKey) return;
    lastRenderKey = key;

    const $container = $('#vast_widget_container');
    const warning = data.warning ? `<div class="vast-msg-box vast-warn orange-text">${escapeHtml(data.warning)}</div>` : '';
    if (machines.length === 0) {
      $container.html(`
        ${warning}
        <div class="vast-msg-box grey-text">
          <i class="fa fa-server vast-msg-icon"></i>
          No host machines found on this Vast.ai account.
        </div>
      `);
      return;
    }

    const earn = num(sum.total_earn_hour);
    const earnSummary = earn >= 0.00005
      ? `Total Earnings: <strong><span class="green-text">+${formatRate(earn)}</span></strong> (Est. ${formatMoney(sum.total_earn_day)}/day)`
      : 'Total Earnings: <strong>$0.00/hr</strong> (Idle)';
    // null means the balance is not known. It is hidden, never shown as $0.00.
    const known = sum.account_balance !== null && sum.account_balance !== undefined;
    const balanceText = known ? ` | Balance: <strong>${formatMoney(sum.account_balance)}</strong>` : '';

    $container.html(`
      ${warning}
      <div class="vast-summary-line">
        <div>${earnSummary}${balanceText}</div>
        <div><a class="vast-toggle-details" data-vast="details">${showHardwareDetails ? 'Hide details' : 'Show details'}</a></div>
      </div>
      <table class="vast-table">
        <thead>
          <tr>
            <th class="vast-col-device">DEVICE</th>
            <th class="vast-col-status">STATUS</th>
            <th class="vast-col-rate">RATE</th>
            <th class="vast-col-temp">TEMP</th>
            <th class="vast-col-smart">RELIABILITY</th>
            <th class="vast-col-util" title="Share of this machine's GPUs that are rented">UTILIZATION</th>
          </tr>
        </thead>
        <tbody>${machines.map(renderMachineRow).join('')}</tbody>
      </table>
    `);
  }

  // Replaces the table with a message. `link` is [text, href or data-vast action].
  function showMessage(subtitle, icon, colour, text, link) {
    lastRenderKey = null;
    $('#vast_subtitle').text(subtitle);
    const attr = link && link[1].startsWith('/') ? `href="${link[1]}"` : `data-vast="${link ? link[1] : ''}"`;
    $('#vast_widget_container').html(`
      <div class="vast-msg-box ${colour}">
        <i class="fa ${icon} vast-msg-icon"></i>
        ${escapeHtml(text)}
        ${link ? `<br><br><a ${attr}>${link[0]}</a>` : ''}
      </div>
    `);
  }

  const SETTINGS = ['Go to Vast.ai Settings', '/Settings/VastAISettings'];

  function refresh(force) {
    if (isFetching) return;
    isFetching = true;
    const $icon = $('#vast_refresh_icon');
    $icon.addClass('spinning');

    $.ajax({
      url: ENDPOINT + (force ? '?force=1' : ''),
      type: 'GET',
      dataType: 'json',
      cache: false,
      // Above the backend's worst case (9 s machines + 5 s balance), so a slow
      // API reads as slow and not as the local service being down.
      timeout: 20000,
      success: function (data) {
        $icon.removeClass('spinning');
        isFetching = false;

        if (data && data.success) {
          lastData = data;
          renderTile(data);
        } else if (data && data.code === 'not_configured') {
          // Nothing changes until the user saves a key, which is a page load.
          setupPolling(0);
          showMessage('Not Configured', 'fa-key', 'blue-text', data.error || 'Please configure your Vast.ai API Key.', SETTINGS);
        } else if (data && data.code === 'auth') {
          // Retrying cannot fix a rejected key. Changing it can.
          showMessage('API Key Rejected', 'fa-key', 'red-text', data.error, SETTINGS);
        } else {
          showMessage('Vast.ai Unreachable', 'fa-exclamation-triangle', 'red-text',
            data && data.error ? data.error : 'Failed to connect to Vast.ai API.', ['Retry', 'retry']);
        }
      },
      error: function (xhr, status) {
        $icon.removeClass('spinning');
        isFetching = false;
        if (status === 'parsererror') {
          // The login page came back in place of JSON: the session ended, or
          // the server restarted. Polling cannot recover from that.
          setupPolling(0);
          showMessage('Signed Out', 'fa-sign-in', 'red-text', 'The Unraid session has ended. Reload the page to sign in again.');
        } else {
          showMessage('Error', 'fa-plug', 'red-text', `Failed to reach the plugin on this server (${status}).`, ['Retry', 'retry']);
        }
      }
    });
  }

  function setupPolling(intervalSec) {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
    const sec = parseInt(intervalSec, 10);
    if (!isNaN(sec) && sec > 0) {
      pollTimer = setInterval(function () {
        // A hidden tab and a closed tile have nobody to show new data to.
        if (!document.hidden && $('#vast_tile').is(':visible')) refresh(false);
      }, sec * 1000);
    }
  }

  $(function () {
    const $tile = $('#vast_tile');
    if (!$tile.length) return;

    $('#vast_int').on('change', function () {
      setupPolling($(this).val());
    });

    $('#vast_btn_refresh').on('click', function (e) {
      e.preventDefault();
      refresh(true);
    });

    // One handler for the links the tile draws inside its body.
    $('#vast_widget_container').on('click', '[data-vast]', function (e) {
      e.preventDefault();
      if (this.getAttribute('data-vast') === 'retry') {
        refresh(true);
      } else if (lastData) {
        // Showing details is local state. Redraw what is already here.
        showHardwareDetails = !showHardwareDetails;
        renderTile(lastData);
      }
    });

    // Coming back to the tab, or waking the laptop, should not leave old data
    // on screen until the next tick.
    document.addEventListener('visibilitychange', function () {
      if (!document.hidden && pollTimer) refresh(false);
    });

    // ?? not ||: 0 is the "Off" setting and must stay 0.
    setupPolling($tile.data('interval') ?? 30);
    refresh(false);
  });
})();
