/**
 * Vast.ai Unraid Plugin - Native Dashboard Renderer
 * Implements Unraid's standardized tabular dashboard design language.
 */

(function () {
  'use strict';

  let pollTimer = null;
  let isFetching = false;
  let showHardwareDetails = false;
  let lastData = null;

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatMoney(amount, decimals = 2) {
    const num = parseFloat(amount) || 0;
    return '$' + num.toFixed(decimals);
  }

  function formatRate(amount) {
    const num = parseFloat(amount) || 0;
    if (num === 0) return '$0.00/hr';
    if (num < 0.01) return '$' + num.toFixed(4) + '/hr';
    return '$' + num.toFixed(3) + '/hr';
  }

  /**
   * Render table row for a single machine
   */
  function renderMachineRow(m, index) {
    const isRented = !!m.is_rented;
    const isOnline = !!m.is_online;
    const isListed = !!m.listed;

    // Status Column
    let statusHtml = '';
    if (!isOnline) {
      statusHtml = `
        <span class="vast-status-indicator vast-status-offline">
          <span class="vast-status-dot"></span> offline
        </span>
        <div class="vast-status-sub">Unreachable</div>
      `;
    } else if (isRented) {
      // occupancy_code is a per-GPU string (one char per GPU), so test for the
      // letter rather than comparing the whole string to a single character.
      const occ = m.occupancy_code || '';
      let occDesc = 'Active';
      if (occ.includes('D')) occDesc = 'On-Demand';
      else if (occ.includes('R')) occDesc = 'Reserved';
      else if (occ.includes('I')) occDesc = 'Interruptible';

      const runningCount = m.running_rentals || 1;
      statusHtml = `
        <span class="vast-status-indicator vast-status-active">
          <span class="vast-status-dot"></span> active
        </span>
        <div class="vast-status-sub">${occDesc} • ${runningCount} ${runningCount === 1 ? 'job' : 'jobs'}</div>
      `;
    } else if (isListed) {
      statusHtml = `
        <span class="vast-status-indicator vast-status-idle">
          <span class="vast-status-dot"></span> listed
        </span>
        <div class="vast-status-sub">Ready for rent</div>
      `;
    } else {
      statusHtml = `
        <span class="vast-status-indicator vast-status-unlisted">
          <span class="vast-status-dot"></span> unlisted
        </span>
        <div class="vast-status-sub">Not listed</div>
      `;
    }

    // Rate Column
    let rateHtml = '';
    if (isRented) {
      rateHtml = `
        <span class="vast-rate-val vast-rate-earning">+${formatRate(m.earn_hour)}</span>
        <div class="vast-rate-sub">Est. ${formatMoney(m.earn_day)}/day</div>
      `;
    } else if (isListed && isOnline) {
      // listed_gpu_cost and min_bid_price are PER GPU, while earn_hour in the
      // branch above is per machine. Scale to machine level so the same column
      // always means the same thing, and show the per-GPU ask alongside it.
      const gpus = m.num_gpus || 1;
      const askMachine = (parseFloat(m.listed_gpu_cost) || 0) * gpus;
      const minMachine = (parseFloat(m.min_bid_price) || 0) * gpus;
      rateHtml = `
        <span class="vast-rate-val">${formatRate(askMachine)}</span>
        <div class="vast-rate-sub">Min: ${formatRate(minMachine)}${gpus > 1 ? ` &middot; ${formatRate(m.listed_gpu_cost)}/GPU` : ''}</div>
      `;
    } else {
      rateHtml = `
        <span class="vast-rate-val" style="opacity: .6;">$0.00/hr</span>
        <div class="vast-rate-sub">-</div>
      `;
    }

    // Temperature Column
    let tempHtml = '-';
    if (m.gpu_temp !== null && m.gpu_temp !== undefined) {
      let tempClass = 'vast-temp-cool';
      if (m.gpu_temp >= 80) tempClass = 'vast-temp-hot';
      else if (m.gpu_temp >= 65) tempClass = 'vast-temp-warm';
      tempHtml = `<span class="${tempClass}">${m.gpu_temp} °C</span>`;
    }

    // Reliability Column (SMART healthy style)
    let reliabHtml = '-';
    if (m.reliability !== null && m.reliability !== undefined) {
      if (m.reliability >= 95) {
        reliabHtml = `<span class="vast-smart-ok"><i class="fa fa-check"></i> ${m.reliability}%</span>`;
      } else {
        reliabHtml = `<span class="vast-smart-warn"><i class="fa fa-exclamation-triangle"></i> ${m.reliability}%</span>`;
      }
    }

    // Utilization Bar Column
    let utilPercent = 0;
    let fillClass = 'fill-gray';
    if (isRented) {
      utilPercent = 100;
      fillClass = (m.occupancy_code || '').includes('I') ? 'fill-amber' : 'fill-green';
    } else if (isListed) {
      utilPercent = 0;
      fillClass = 'fill-blue';
    }

    const utilHtml = `
      <span class="vast-util-text">${utilPercent}%</span>
      <div class="vast-util-bar">
        <div class="vast-util-fill ${fillClass}" style="width: ${utilPercent}%;"></div>
      </div>
    `;

    // Main row
    let html = `
      <tr class="vast-row">
        <td class="vast-col-device">
          <div class="vast-dev-name">
            <i class="fa fa-desktop vast-icon-host"></i>
            <span>${escapeHtml(m.hostname)}</span>
          </div>
          <div class="vast-dev-sub">#${escapeHtml(m.id)} • ${m.num_gpus}x ${escapeHtml(m.gpu_name)} ${m.gpu_ram_gb ? `(${m.gpu_ram_gb} GB)` : ''}</div>
        </td>
        <td class="vast-col-status">${statusHtml}</td>
        <td class="vast-col-rate">${rateHtml}</td>
        <td class="vast-col-temp">${tempHtml}</td>
        <td class="vast-col-smart">${reliabHtml}</td>
        <td class="vast-col-util">${utilHtml}</td>
      </tr>
    `;

    // Expandable hardware details row
    if (showHardwareDetails) {
      const details = [];
      if (m.cpu_name) {
        details.push(`<div class="vast-details-item"><i class="fa fa-server"></i> CPU: <strong>${escapeHtml(m.cpu_name)}${m.cpu_cores ? ` (${m.cpu_cores}C)` : ''}</strong></div>`);
      }
      if (m.cpu_ram_gb) {
        details.push(`<div class="vast-details-item"><i class="fa fa-memory"></i> RAM: <strong>${m.cpu_ram_gb} GB</strong></div>`);
      }
      if (m.disk_space_gb) {
        details.push(`<div class="vast-details-item"><i class="fa fa-hdd-o"></i> Disk: <strong>${m.disk_space_gb} GB (${m.avail_disk_gb} GB Free)</strong></div>`);
      }
      if (m.inet_down_mbps && m.inet_up_mbps) {
        details.push(`<div class="vast-details-item"><i class="fa fa-exchange"></i> Net: <strong>${m.inet_down_mbps} ↓ / ${m.inet_up_mbps} ↑ Mbps</strong></div>`);
      }
      if (m.verification) {
        details.push(`<div class="vast-details-item"><i class="fa fa-shield"></i> Host Status: <strong>${escapeHtml(m.verification)}</strong></div>`);
      }

      html += `
        <tr class="vast-details-row">
          <td colspan="6">
            <div class="vast-details-grid">
              ${details.join('')}
            </div>
          </td>
        </tr>
      `;
    }

    return html;
  }

  function renderTile(data) {
    const $container = $('#vast_widget_container');
    const $subtitle = $('#vast_subtitle');

    const sum = data.summary || {};
    const totalEarn = sum.total_earn_hour || 0;
    const totalEarnDay = sum.total_earn_day || 0;
    const balance = sum.account_balance || 0;
    const rentedCount = sum.rented_machines || 0;
    const totalCount = sum.total_machines || 0;
    const machines = data.machines || [];

    // Subtitle. The backend emits stale/cached/cache_age on its degradation
    // paths, so surface them instead of always claiming ONLINE.
    const rented = `${rentedCount} of ${totalCount} Rented`;
    if (data.stale) {
      $subtitle.html(`<span class="vast-stale">Status: STALE</span> • ${rented}`);
    } else if (data.cached && data.cache_age > 0) {
      $subtitle.html(`Status: ONLINE • ${rented} • cached ${data.cache_age}s ago`);
    } else {
      $subtitle.html(`Status: ONLINE • ${rented}`);
    }


    // Summary Line (matching PROCESSOR "Total Power 103.74 W | Temperature: 78 °C" / ARRAY "3.98 TB used...")
    let earnSummary = '';
    if (totalEarn > 0) {
      earnSummary = `Total Earnings: <strong class="rate-highlight">+${formatRate(totalEarn)}</strong> (Est. ${formatMoney(totalEarnDay, 2)}/day)`;
    } else {
      earnSummary = `Total Earnings: <strong>$0.00/hr</strong> (Idle)`;
    }

    const balanceText = balance > 0 ? ` | Balance: <strong>${formatMoney(balance, 2)}</strong>` : '';
    const detailsToggleLabel = showHardwareDetails ? 'Hide details' : 'Show details';

    const summaryLineHtml = `
      <div class="vast-summary-line">
        <div>
          ${earnSummary}${balanceText}
        </div>
        <div>
          <a class="vast-toggle-details" id="vast_toggle_details">${detailsToggleLabel}</a>
        </div>
      </div>
    `;

    // Table
    if (machines.length === 0) {
      $container.html(`
        <div class="vast-msg-box">
          <i class="fa fa-server" style="font-size: 20px; opacity: 0.5; margin-bottom: 6px; display: block;"></i>
          No host machines found on this Vast.ai account.
        </div>
      `);
      return;
    }

    const rowsHtml = machines.map(renderMachineRow).join('');

    const tableHtml = `
      ${summaryLineHtml}
      <table class="vast-table">
        <thead>
          <tr>
            <th class="vast-col-device">DEVICE</th>
            <th class="vast-col-status">STATUS</th>
            <th class="vast-col-rate">RATE</th>
            <th class="vast-col-temp">TEMP</th>
            <th class="vast-col-smart">RELIABILITY</th>
            <th class="vast-col-util">UTILIZATION</th>
          </tr>
        </thead>
        <tbody>
          ${rowsHtml}
        </tbody>
      </table>
    `;

    $container.html(tableHtml);

    if (data.warning) {
      $container.prepend(`<div class="vast-msg-box vast-warn">${escapeHtml(data.warning)}</div>`);
    }

    // Toggling details is pure local state. Re-render from the payload we
    // already have instead of refetching, which also avoids the in-flight
    // isFetching guard swallowing the click.
    $('#vast_toggle_details').off('click').on('click', function (e) {
      e.preventDefault();
      showHardwareDetails = !showHardwareDetails;
      if (lastData) renderTile(lastData);
    });
  }

  /**
   * Main refresh and render
   */
  window.vastai_refresh = function (force = false) {
    if (isFetching) return;
    isFetching = true;

    const $icon = $('#vast_refresh_icon');
    const $container = $('#vast_widget_container');
    const $subtitle = $('#vast_subtitle');

    if ($icon.length) $icon.addClass('spinning');

    const url = '/plugins/vastai/include/getvaststatus.php' + (force ? '?force=1' : '');

    $.ajax({
      url: url,
      type: 'GET',
      dataType: 'json',
      cache: false,
      timeout: 12000,
      success: function (data) {
        if ($icon.length) $icon.removeClass('spinning');
        isFetching = false;

        if (!data || !data.success) {
          if (data && data.not_configured) {
            $subtitle.text('Not Configured');
            $container.html(`
              <div class="vast-msg-box">
                <i class="fa fa-key" style="font-size: 20px; color: #38bdf8; margin-bottom: 6px; display: block;"></i>
                ${escapeHtml(data.error || 'Please configure your Vast.ai API Key.')}
                <br><br>
                <a href="/Settings/VastAISettings">Go to Vast.ai Settings</a>
              </div>
            `);
            return;
          }

          $subtitle.text('Connection Error');
          $container.html(`
            <div class="vast-msg-box" style="color: #ff3b30;">
              <i class="fa fa-exclamation-triangle" style="font-size: 20px; margin-bottom: 6px; display: block;"></i>
              ${escapeHtml(data ? data.error : 'Failed to connect to Vast.ai API.')}
              <br><br>
              <a href="javascript:void(0)" onclick="window.vastai_refresh(true)">Retry Connection</a>
            </div>
          `);
          return;
        }

        lastData = data;
        renderTile(data);
      },
      error: function (xhr, status, err) {
        if ($icon.length) $icon.removeClass('spinning');
        isFetching = false;
        $subtitle.text('Error');
        $container.html(`
          <div class="vast-msg-box" style="color: #ff3b30;">
            <i class="fa fa-plug" style="font-size: 20px; margin-bottom: 6px; display: block;"></i>
            Failed to connect to local Vast.ai service (${escapeHtml(status)}).
            <br><br>
            <a href="javascript:void(0)" onclick="window.vastai_refresh(true)">Retry</a>
          </div>
        `);
      }
    });
  };

  function setupPolling(intervalSec) {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }

    const sec = parseInt(intervalSec, 10);
    if (!isNaN(sec) && sec > 0) {
      pollTimer = setInterval(function () {
        if (!document.hidden) {
          window.vastai_refresh(false);
        }
      }, sec * 1000);
    }
  }

  $(function () {
    const $tile = $('#vast_tile');
    if (!$tile.length) return;

    const initialInterval = $tile.data('interval') || 30;

    $('#vast_int').on('change', function () {
      setupPolling($(this).val());
    });

    $('#vast_btn_refresh').on('click', function (e) {
      e.preventDefault();
      window.vastai_refresh(true);
    });

    setupPolling(initialInterval);
    window.vastai_refresh(false);
  });
})();
