

'use strict';

RBAC.initPage({ allowed: ['Admin', 'Cashier'] });

/* Session Guard */
const SESSION_KEY = 'pawpos_session';

function getSession() {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(SESSION_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch { return null; }
}

function isSessionValid(s) {
  return s && s.userId && (Date.now() - s.loginTime) < 8 * 60 * 60 * 1000;
}

(function sessionGuard() {
  const session = getSession();
  if (!isSessionValid(session)) { window.location.replace('index.html'); return; }
  const el = document.getElementById('userAvatar');
  if (el) el.textContent = (session.name || 'U').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
})();

/* Constants */

const REPORT_TYPES = [
  { id: 'daily',       name: 'Daily Sales',      icon: 'ti-sun',                  iconBg: '#e1f5ee', iconFg: '#0f6e56' },
  { id: 'weekly',      name: 'Weekly Sales',      icon: 'ti-calendar-week',        iconBg: '#e6f1fb', iconFg: '#185fa5' },
  { id: 'monthly',     name: 'Monthly Sales',     icon: 'ti-calendar',             iconBg: '#faeeda', iconFg: '#854f0b' },
  { id: 'inventory',   name: 'Inventory',         icon: 'ti-building-warehouse',   iconBg: '#f0ebfb', iconFg: '#6c3fc5' },
  { id: 'appointment', name: 'Appointments',      icon: 'ti-scissors',             iconBg: '#fbeaf0', iconFg: '#963d5a' },
];

const PERIODS = ['Daily', 'Weekly', 'Monthly', 'Custom'];
const esc = value => window.PawUI ? PawUI.escapeHtml(value) : String(value ?? '');

/* State */
const state = {
  reportType: 'daily',
  period:     'Daily',
  dateFrom:   '',
  dateTo:     ''
};

/* Api Layer */

/**
 * Fetches report data for the given type and date range.
 * Replace with actual API call in production.
 * @param {string} reportType
 * @param {string} dateFrom
 * @param {string} dateTo
 * @returns {Promise<Object>}
 */
async function apiGetReportData(reportType, dateFrom, dateTo) {
  const params = new URLSearchParams({ type: reportType, date_from: dateFrom || '', date_to: dateTo || '' });
  const raw = await PawApi.get(`reports.php?${params.toString()}`);
  return normalizeReportData(raw, reportType);
}

function normalizeReportData(raw, reportType) {
  const summary = raw.summary || {};
  const summaryKeys = reportType === 'inventory'
    ? ['total_skus', 'out_of_stock', 'low_stock', 'total_stock_value']
    : reportType === 'appointment'
      ? ['total', 'completed', 'upcoming', 'cancelled']
      : ['transaction_count', 'total_revenue', 'avg_ticket', 'growth_pct'];
  const moneyKeys = new Set(['total_revenue', 'avg_ticket', 'total_stock_value', 'total_discounts', 'total_tax']);
  const icons = ['ti-chart-bar', 'ti-cash', 'ti-receipt', 'ti-trending-up'];
  const classes = ['si-green', 'si-blue', 'si-amber', 'si-purple'];
  const summaryCards = summaryKeys.map((key, index) => {
    const value = summary[key] ?? 0;
    return {
      label: key.replaceAll('_', ' ').replace(/\b\w/g, c => c.toUpperCase()),
      val: moneyKeys.has(key) ? peso(value) : key === 'growth_pct' ? `${value ?? 0}%` : Number(value).toLocaleString('en-PH'),
      meta: 'Current selected period',
      icon: icons[index],
      cls: classes[index]
    };
  });

  const chart = raw.chart || {};
  const table = raw.table || {};
  const cols = table.cols || table.columns || [];
  const moneyHeaders = new Set(['Subtotal', 'Discount', 'Total', 'Cost', 'Price', 'Revenue', 'Amount']);
  const align = table.align || cols.map(col => moneyHeaders.has(col) ? 'right' : 'left');
  const statusCol = Number.isInteger(table.statusCol) ? table.statusCol : cols.indexOf('Status');

  return {
    ...raw,
    summary: Array.isArray(raw.summary) ? raw.summary : summaryCards,
    chart: {
      title: chart.title || 'Report chart',
      labels: chart.labels || [],
      primary: chart.primary || chart.values || [],
      secondary: chart.secondary || chart.secondary_values || null,
      primaryLabel: chart.primaryLabel || chart.primary_label || 'Value',
      secondaryLabel: chart.secondaryLabel || chart.secondary_label || ''
    },
    table: {
      ...table,
      cols,
      align,
      rows: table.rows || [],
      foot: table.foot || table.footer || [],
      statusCol,
      statusMap: table.statusMap || {
        Active: 'active', Completed: 'completed', Confirmed: 'confirmed',
        Pending: 'pending', Cancelled: 'cancelled', OK: 'active',
        'Low stock': 'pending', 'Out of stock': 'cancelled'
      }
    }
  };
}

/* Report Filtering */

/**
 * Returns default date range values based on the selected period.
 * @param {string} period - 'Daily' | 'Weekly' | 'Monthly' | 'Custom'
 * @returns {{ from: string, to: string }}
 */
function getDefaultDateRange(period) {
  const now = new Date();
  const fmt = d => d.toISOString().split('T')[0];
  const to  = fmt(now);

  switch (period) {
    case 'Daily': {
      return { from: to, to };
    }
    case 'Weekly': {
      const d = new Date(now);
      d.setDate(now.getDate() - 6);
      return { from: fmt(d), to };
    }
    case 'Monthly': {
      return { from: `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-01`, to };
    }
    case 'Custom':
    default: {
      const d = new Date(now);
      d.setFullYear(now.getFullYear() - 1);
      return { from: fmt(d), to };
    }
  }
}

/**
 * Applies the current filter state and refreshes the report view.
 * Called on period change, date input, or Apply button.
 */
async function applyFilter() {
  const dateFrom = document.getElementById('dateFrom')?.value || state.dateFrom;
  const dateTo   = document.getElementById('dateTo')?.value   || state.dateTo;

  state.dateFrom = dateFrom;
  state.dateTo   = dateTo;

  await loadReport();
}

/**
 * Validates the date range inputs.
 * @returns {{ valid: boolean, message: string }}
 */
function validateDateRange(from, to) {
  if (!from || !to) return { valid: false, message: 'Please select both start and end dates.' };
  if (from > to)    return { valid: false, message: 'Start date cannot be after end date.' };
  return { valid: true, message: '' };
}

/* Data Aggregation Helpers */

/**
 * Calculates percentage change between two values.
 * @param {number} current
 * @param {number} previous
 * @returns {string} e.g. "+8.4%" or "−2.1%"
 */
function pctChange(current, previous) {
  if (!previous) return '—';
  const delta = ((current - previous) / previous) * 100;
  return (delta >= 0 ? '+' : '−') + Math.abs(delta).toFixed(1) + '%';
}

/**
 * Returns the sum of an array of numbers.
 * @param {number[]} arr
 * @returns {number}
 */
function sum(arr) { return arr.reduce((a, b) => a + b, 0); }

/**
 * Returns the maximum value in an array.
 * @param {number[]} arr
 * @returns {number}
 */
function max(arr) { return Math.max(...arr); }

/**
 * Formats a number as Philippine Peso.
 * @param {number} n
 * @returns {string}
 */
function peso(n) {
  return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/**
 * Formats a large number compactly (e.g. 18000 → "₱18k").
 * @param {number} n
 * @returns {string}
 */
function pesoCompact(n) {
  if (n >= 1_000_000) return '₱' + (n / 1_000_000).toFixed(1) + 'M';
  if (n >= 1_000)     return '₱' + (n / 1_000).toFixed(0) + 'k';
  return '₱' + n;
}

/**
 * Returns today's date as an ISO string.
 * @returns {string}
 */
function todayISO() { return new Date().toISOString().split('T')[0]; }

/* Data Visualization — Bar Chart */

/**
 * Renders a pure CSS/HTML bar chart.
 * Supports single-series and dual-series (comparison) charts.
 * @param {Object} chartData - { title, labels, primary, secondary?, primaryLabel, secondaryLabel? }
 */
function renderBarChart(chartData) {
  const container = document.getElementById('chartCard');
  if (!container) return;

  const { title, labels, primary, secondary, primaryLabel, secondaryLabel } = chartData;
  const maxVal  = max([...primary, ...(secondary || [])]);
  const H       = 88;   // compact plot height

  const legend = `
    <div class="chart-legend">
      <span><span class="legend-dot" style="background:#1a3a2e"></span>${esc(primaryLabel)}</span>
      ${secondary ? `<span><span class="legend-dot" style="background:#c8e6d8"></span>${esc(secondaryLabel)}</span>` : ''}
    </div>
  `;

  /**
   * Computes bar height as a percentage of the chart area.
   * Ensures a minimum visible height of 4px even for zero values.
   * @param {number} value
   * @returns {number} Height in px.
   */
  function barHeight(value) {
    return maxVal ? Math.max(4, Math.round((value / maxVal) * H)) : 4;
  }

  const bars = labels.map((label, i) => {
    const h1  = barHeight(primary[i]);
    const h2  = secondary ? barHeight(secondary[i]) : 0;

    // Build tooltip text
    const tip1 = `${primaryLabel}: ${primary[i] >= 1000 ? pesoCompact(primary[i]) : primary[i]}`;
    const tip2 = secondary ? `${secondaryLabel}: ${secondary[i] >= 1000 ? pesoCompact(secondary[i]) : secondary[i]}` : '';

    // Compact y-axis label for the primary bar
    const valLabel = primary[i] > 0
      ? (primary[i] >= 1000 ? pesoCompact(primary[i]) : primary[i])
      : '';

    return `
      <div class="bar-col">
        <div class="bar-val">${valLabel}</div>
        <div class="bar-wrap" style="height:${H}px;align-items:flex-end">
          <div class="bar primary"
            style="height:${h1}px;width:${secondary ? '34px' : '52px'}"
            title="${tip1}"
            role="img"
            aria-label="${tip1}">
          </div>
          ${secondary ? `
            <div class="bar secondary"
              style="height:${h2}px;width:34px"
              title="${tip2}"
              role="img"
              aria-label="${tip2}">
            </div>
          ` : ''}
        </div>
        <div class="bar-label">${esc(label)}</div>
      </div>
    `;
  }).join('');

  container.innerHTML = `
    <div class="chart-header">
      <span class="chart-title">${esc(title)}</span>
      ${legend}
    </div>
    <div class="bar-chart" role="img" aria-label="${title}">
      ${bars}
    </div>
    <div class="chart-footer">
      <span>${primaryLabel} total: ${sum(primary) >= 1000 ? pesoCompact(sum(primary)) : sum(primary)}</span>
      ${secondary ? `<span>${secondaryLabel} total: ${sum(secondary) >= 1000 ? pesoCompact(sum(secondary)) : sum(secondary)}</span>` : ''}
    </div>
  `;
}

/* Render: Summary Cards */

/**
 * Renders the 4 summary stat cards for the current report type.
 * @param {Object[]} summaryData
 */
function renderSummary(summaryData) {
  const el = document.getElementById('summaryRow');
  if (!el) return;

  el.innerHTML = summaryData.map(s => `
    <div class="sum-card">
      <div class="sum-top">
        <span class="sum-label">${s.label}</span>
        <div class="sum-icon ${s.cls}"><i class="ti ${s.icon}" aria-hidden="true"></i></div>
      </div>
      <div class="sum-val">${s.val}</div>
      <div class="sum-meta">${s.meta}</div>
    </div>
  `).join('');
}

/* Render: Report Table */

/**
 * Renders the report data table.
 * Applies status badge colouring and percentage colouring automatically.
 * @param {Object} tableData - { title, cols, align, rows, foot, statusCol, statusMap }
 */
function renderTable(tableData) {
  const wrap = document.getElementById('reportTableWrap');
  if (!wrap) return;

  const { title, cols = [], align = [], rows = [], foot = [], statusCol = -1, statusMap = {} } = tableData;

  /**
   * Applies visual treatment to a cell value:
   * - Status badges from statusMap
   * - Green for positive percentages, red for negative
   * @param {string} cell
   * @param {number} colIdx
   * @returns {string} HTML string
   */
  function styleCell(cell, colIdx) {
    // Status badge
    if (colIdx === statusCol && statusMap[cell]) {
      return `<span class="badge ${statusMap[cell]}">${cell}</span>`;
    }
    // Positive percentage
    if (typeof cell === 'string' && /^\+[\d.]+%$/.test(cell)) {
      return `<span style="color:#0f6e56;font-weight:500">${cell}</span>`;
    }
    // Negative percentage
    if (typeof cell === 'string' && /^[−-][\d.]+%$/.test(cell)) {
      return `<span style="color:#a32d2d;font-weight:500">${cell}</span>`;
    }
    return esc(cell);
  }

  const thead = cols.map((c, i) =>
    `<th class="${align[i] === 'right' ? 'right' : ''}">${c}</th>`
  ).join('');

  const tbody = rows.map(row => {
    const cells = row.map((cell, i) =>
      `<td class="${align[i] === 'right' ? 'right' : ''}">${styleCell(cell, i)}</td>`
    ).join('');
    return `<tr>${cells}</tr>`;
  }).join('');

  const tfoot = foot && foot.some(f => f) ? `
    <tfoot><tr>
      ${foot.map((f, i) => `<td class="${align[i] === 'right' ? 'right' : ''}">${f}</td>`).join('')}
    </tr></tfoot>
  ` : '';

  wrap.innerHTML = `
    <div class="table-head-row">
      <span class="table-head-title">${title}</span>
      <div class="export-btns">
        <button class="export-btn print" id="exportPrintBtn"type="button"><i class="ti ti-printer" aria-hidden="true"></i> Print</button>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="tbl">
        <thead><tr>${thead}</tr></thead>
        <tbody>${tbody}</tbody>
        ${tfoot}
      </table>
    </div>
    ${!rows.length ? '<div class="empty-report"><i class="ti ti-file-off" aria-hidden="true"></i><p>No data for this period.</p></div>' : ''}
  `;

  // Bind inline export buttons
  document.getElementById('exportPrintBtn')?.addEventListener('click', () => window.print());
}

/* Report Export */

/**
 * Exports the current table data as a downloadable CSV file.
 * Includes headers, all data rows, and the footer totals row.
 * @param {Object} tableData
 */
function exportCSV(tableData) {
  const { cols, rows, foot } = tableData;

  const allRows = [cols, ...rows];
  if (foot && foot.some(f => f)) allRows.push(foot);

  // CSV-encode: wrap every cell in double quotes, escape internal quotes
  const csvContent = allRows
    .map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
    .join('\n');

  const filename = `pawpos_${state.reportType}_${state.dateFrom || todayISO()}_${state.dateTo || todayISO()}.csv`;
  downloadBlob(csvContent, filename, 'text/csv;charset=utf-8;');
}

/**
 * Creates a temporary anchor element and triggers a file download.
 * @param {string} content  - File content string.
 * @param {string} filename - Desired filename.
 * @param {string} mimeType - MIME type string.
 */
function downloadBlob(content, filename, mimeType) {
  const blob = new Blob(['\uFEFF' + content], { type: mimeType }); // BOM for Excel compat
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

/* Render: Report Type Cards */

/**
 * Renders the 6 report type selector cards.
 */
function renderReportTypeCards() {
  const el = document.getElementById('reportTypes');
  if (!el) return;

  el.innerHTML = REPORT_TYPES.map(rt => `
    <div
      class="report-type-card ${state.reportType === rt.id ? 'active' : ''}"
      data-id="${rt.id}"
      tabindex="0"
      role="button"
      aria-pressed="${state.reportType === rt.id}"
      aria-label="${rt.name} report"
    >
      <div class="rtype-icon" style="background:${state.reportType === rt.id ? 'rgba(255,255,255,0.15)' : rt.iconBg};color:${state.reportType === rt.id ? '#fff' : rt.iconFg}">
        <i class="ti ${rt.icon}" aria-hidden="true"></i>
      </div>
      <div class="rtype-info">
        <div class="rtype-name">${rt.name}</div>
      </div>
    </div>
  `).join('');

  el.querySelectorAll('.report-type-card').forEach(card => {
    card.addEventListener('click', () => {
      state.reportType = card.dataset.id;
      renderReportTypeCards();
      loadReport();
    });
    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); state.reportType = card.dataset.id; renderReportTypeCards(); loadReport(); }
    });
  });
}

/* Render: Period Tabs */

/**
 * Renders the period tab selector (Daily / Weekly / Monthly / Custom).
 */
function renderPeriodTabs() {
  const el = document.getElementById('periodTabs');
  if (!el) return;

  el.innerHTML = PERIODS.map(p => `
    <button class="period-tab ${state.period === p ? 'active' : ''}" data-period="${p}" aria-pressed="${state.period === p}">${p}</button>
  `).join('');

  el.querySelectorAll('.period-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      state.period = btn.dataset.period;
      renderPeriodTabs();
      setDateInputsForPeriod(state.period);
      loadReport();
    });
  });
}

/**
 * Sets the date inputs to defaults for the selected period.
 * @param {string} period
 */
function setDateInputsForPeriod(period) {
  const { from, to } = getDefaultDateRange(period);
  const fromEl = document.getElementById('dateFrom');
  const toEl   = document.getElementById('dateTo');
  if (fromEl) fromEl.value = from;
  if (toEl)   toEl.value   = to;
  state.dateFrom = from;
  state.dateTo   = to;
}

/* Print Header */

/**
 * Updates the hidden print header with current report details.
 * This block is revealed by the @media print CSS rule.
 */
function updatePrintHeader() {
  const rtype    = REPORT_TYPES.find(r => r.id === state.reportType);
  const subtitle = document.getElementById('printSubtitle');
  const date     = document.getElementById('printDate');
  if (subtitle) subtitle.textContent = `${rtype?.name || ''} Report · ${state.period}`;
  if (date)     date.textContent     = `Generated: ${new Date().toLocaleString('en-PH')}`;
}

/* Main Load Function */

/**
 * Loads report data for the current state and renders all sections.
 */
async function loadReport() {
  // Show loading state in chart and table
  const chartEl = document.getElementById('chartCard');
  const tableEl = document.getElementById('reportTableWrap');
  if (chartEl) chartEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#ccc;font-size:13px">Loading chart...</div>';
  if (tableEl) tableEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#ccc;font-size:13px">Loading data...</div>';

  try {
    const data = await apiGetReportData(state.reportType, state.dateFrom, state.dateTo);
    renderSummary(data.summary);
    renderBarChart(data.chart);
    renderTable(data.table);
    updatePrintHeader();
  } catch (err) {
    console.error('[PAWPOS] Report load error:', err);
    if (chartEl) chartEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#c0392b;font-size:13px">Failed to load chart data.</div>';
    if (tableEl) tableEl.innerHTML = '<div style="text-align:center;padding:2rem;color:#c0392b;font-size:13px">Failed to load report data.</div>';
  }
}

/* Filter Bar Controls */

// Apply filter button
document.getElementById('applyFilter')?.addEventListener('click', async () => {
  const from = document.getElementById('dateFrom')?.value;
  const to   = document.getElementById('dateTo')?.value;
  const { valid, message } = validateDateRange(from, to);
  if (!valid) { alert(message); return; }
  state.dateFrom = from;
  state.dateTo   = to;
  await loadReport();
});

// Date input changes
document.getElementById('dateFrom')?.addEventListener('change', e => { state.dateFrom = e.target.value; });
document.getElementById('dateTo')?.addEventListener('change',   e => { state.dateTo   = e.target.value; });

/* Topbar Export & Print Buttons */

document.getElementById('printBtn')?.addEventListener('click', () => window.print());

/* Sidebar Toggle & Logout */

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  document.getElementById('sidebar')?.classList.toggle('open');
});

document.getElementById('logoutBtn')?.addEventListener('click', () => {
  if (!confirm('Log out of PAWPOS?')) return;
  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);
  localStorage.removeItem('pawpos_remember');
  window.location.replace('index.html');
});

/* Init */
(function init() {
  setDateInputsForPeriod('Daily');
  renderReportTypeCards();
  renderPeriodTabs();
  loadReport();
})();
