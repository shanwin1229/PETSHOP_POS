

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
const PAGE_SIZE          = 10;
const LOW_STOCK_DEFAULT  = 10;   // fallback reorder level if not set
const EXPIRY_WARN_DAYS   = 60;   // days ahead to flag as "expiring soon"

let inventory = [];

let history = [];
let nextHistId = 1;

/* State */
const state = {
  activeTab:   'stock',   // 'stock' | 'low' | 'expiry' | 'history'
  searchQ:     '',
  catFilter:   '',
  currentPage: 1,
  stockInId:   null,      // productId pre-selected in stock-in modal
  stockOutId:  null       // productId pre-selected in stock-out modal
};

/* Api Layer */

function mapInventory(row) {
  return {
    id: Number(row.id),
    name: row.name || '',
    sku: row.sku || '',
    category: row.category || '',
    stock: Number(row.stock_qty ?? row.stock ?? 0),
    reorder: Number(row.reorder_level ?? row.reorder ?? 0),
    expiry: row.expiry_date || row.expiry || '',
    cost: Number(row.cost_price ?? row.cost ?? 0)
  };
}

async function apiGetInventory() {
  const data = await PawApi.get('inventory.php?limit=1000');
  const list = (data.inventory || data.items || data || []).map(mapInventory);
  try {
    const hist = await PawApi.get('inventory.php?action=history&limit=1000');
    history = hist.history || hist.items || [];
  } catch (_) {
    history = [];
  }
  return list;
}

async function apiStockIn(payload) {
  const data = await PawApi.post('inventory.php?action=stock_in', {
    product_id: payload.productId,
    quantity: payload.qty,
    supplier: payload.supplier || '',
    expiry_date: payload.expiry || null,
    remarks: payload.remarks || ''
  });
  return mapInventory(data.item || data.product || data);
}

async function apiStockOut(payload) {
  const data = await PawApi.post('inventory.php?action=stock_out', {
    product_id: payload.productId,
    quantity: payload.qty,
    reason: payload.reason || '',
    remarks: payload.remarks || ''
  });
  return mapInventory(data.item || data.product || data);
}

async function loadInventory() {
  inventory = await apiGetInventory();
  state.currentPage = 1;
  render();
  populateProductSelect('siProduct');
  populateProductSelect('soProduct');
}

/* Stock Computation */

/**
 * Computes the stock status of an inventory item.
 * @param {Object} item
 * @returns {'ok'|'low'|'out'}
 */
function computeStockStatus(item) {
  if (item.stock === 0) return 'out';
  const threshold = item.reorder || LOW_STOCK_DEFAULT;
  if (item.stock <= threshold) return 'low';
  return 'ok';
}

/**
 * Computes the expiry status of an inventory item.
 * @param {string} dateStr - ISO date or empty string.
 * @returns {'expired'|'expiring'|'ok'|'none'}
 */
function computeExpiryStatus(dateStr) {
  if (!dateStr) return 'none';
  const daysLeft = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
  if (daysLeft < 0)                return 'expired';
  if (daysLeft <= EXPIRY_WARN_DAYS) return 'expiring';
  return 'ok';
}

/**
 * Counts how many days remain until expiry.
 * Returns null for items without an expiry date.
 * @param {string} dateStr
 * @returns {number|null}
 */
function daysUntilExpiry(dateStr) {
  if (!dateStr) return null;
  return Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
}

/**
 * Computes the visual fill percentage for the stock progress bar.
 * Caps at 100% and ensures a minimum visible height.
 * @param {Object} item
 * @returns {number} 0–100
 */
function stockBarPercent(item) {
  const max = (item.reorder || LOW_STOCK_DEFAULT) * 4;
  return Math.min(100, Math.max(2, Math.round((item.stock / max) * 100)));
}

/**
 * Returns a CSS class for the stock bar color.
 * @param {Object} item
 * @returns {'ok'|'warn'|'danger'}
 */
function stockBarClass(item) {
  const s = computeStockStatus(item);
  return s === 'out' ? 'danger' : s === 'low' ? 'warn' : 'ok';
}

/* Low Stock Alerts */

/**
 * Returns all items that are low or out of stock.
 * Used for the Low Stock tab and sidebar badge.
 * @returns {Array}
 */
function getLowStockItems() {
  return inventory.filter(item => computeStockStatus(item) !== 'ok');
}

/**
 * Returns all items with an expiry date set (sorted soonest first).
 * Used for the Expiry Monitoring tab.
 * @returns {Array}
 */
function getExpiringItems() {
  return inventory
    .filter(item => item.expiry !== '')
    .sort((a, b) => {
      const da = daysUntilExpiry(a.expiry) ?? 9999;
      const db = daysUntilExpiry(b.expiry) ?? 9999;
      return da - db;
    });
}

/**
 * Returns the count of items with due or overdue vaccinations / expiry flags.
 * Used to update the nav badge.
 * @returns {number}
 */
function getAlertCount() {
  const lowOut  = inventory.filter(i => computeStockStatus(i) !== 'ok').length;
  const expiring = inventory.filter(i => ['expired', 'expiring'].includes(computeExpiryStatus(i.expiry))).length;
  return lowOut + expiring;
}

/**
 * Triggers a browser notification for critical stock levels.
 * Only fires if the browser supports notifications and permission is granted.
 * @param {Object} item
 */
function triggerBrowserAlert(item) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const status = computeStockStatus(item);
  if (status === 'out') {
    new Notification('PAWPOS — Out of Stock', { body: `"${item.name}" is out of stock.`, icon: '🚨' });
  } else if (status === 'low') {
    new Notification('PAWPOS — Low Stock Alert', { body: `"${item.name}" has only ${item.stock} units left.`, icon: '⚠️' });
  }
}

/* History Log */

/**
 * Appends a new entry to the history log.
 * @param {Object} entry - { productId, type, qty, supplier, reason, remarks }
 */
function addHistoryEntry(entry) {
  history.push({
    id:        nextHistId++,
    date:      new Date().toISOString().split('T')[0],
    user:      getSession()?.name || 'Admin',
    ...entry
  });
}

/* Data Getters Per Tab */

/**
 * Returns the filtered list for the current tab.
 * @returns {Array}
 */
function getTabData() {
  let list;

  switch (state.activeTab) {
    case 'low':
      list = getLowStockItems();
      break;
    case 'expiry':
      list = getExpiringItems();
      break;
    case 'history':
      list = [...history].reverse();
      break;
    default:
      list = [...inventory];
  }

  // Apply search filter
  if (state.searchQ) {
    const q = state.searchQ.toLowerCase();
    if (state.activeTab === 'history') {
      list = list.filter(h => {
        const prod = inventory.find(i => i.id === h.productId);
        return prod && (prod.name.toLowerCase().includes(q) || prod.sku.toLowerCase().includes(q));
      });
    } else {
      list = list.filter(i =>
        i.name.toLowerCase().includes(q) ||
        i.sku.toLowerCase().includes(q)
      );
    }
  }

  // Apply category filter (not applicable to history)
  if (state.catFilter && state.activeTab !== 'history') {
    list = list.filter(i => i.category === state.catFilter);
  }

  return list;
}

/**
 * Returns the paginated slice of the current tab data.
 * @returns {{ items: Array, total: number, totalPages: number }}
 */
function getPage() {
  const all        = getTabData();
  const total      = all.length;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  if (state.currentPage > totalPages) state.currentPage = totalPages;
  const start = (state.currentPage - 1) * PAGE_SIZE;
  return { items: all.slice(start, start + PAGE_SIZE), total, totalPages };
}

/* Utility Helpers */

/** Format a number as Philippine Peso. */
function peso(n) {
  return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Return today's date as ISO string. */
function todayISO() {
  return new Date().toISOString().split('T')[0];
}

/** Safely set element text. */
function setText(id, text) {
  const el = document.getElementById(id);
  if (el) el.textContent = text;
}

/* Render: Stat Cards */

/**
 * Refreshes all four inventory stat cards and the nav badge.
 */
function renderStats() {
  const lowCount     = inventory.filter(i => computeStockStatus(i) === 'low').length;
  const outCount     = inventory.filter(i => computeStockStatus(i) === 'out').length;
  const expiringCnt  = inventory.filter(i => ['expired', 'expiring'].includes(computeExpiryStatus(i.expiry))).length;

  setText('statTotal',    inventory.length);
  setText('statLow',      lowCount);
  setText('statOut',      outCount);
  setText('statExpiring', expiringCnt);

  // Nav badge (low + out of stock)
  const badge = document.getElementById('navBadge');
  if (badge) badge.textContent = lowCount + outCount;

  // Topbar sub text
  setText('topbarSub', `${inventory.length} products · ${lowCount} low · ${outCount} out of stock`);
}

/* Render: Table Head */

const TABLE_HEADS = {
  stock: `<tr>
    <th style="width:28%">Product</th>
    <th style="width:13%">Category</th>
    <th style="width:13%">Stock</th>
    <th style="width:11%">Reorder at</th>
    <th style="width:12%">Status</th>
    <th style="width:13%">Expiry</th>
    <th style="width:10%;text-align:right">Actions</th>
  </tr>`,
  low: `<tr>
    <th style="width:28%">Product</th>
    <th style="width:13%">Category</th>
    <th style="width:14%">Stock</th>
    <th style="width:11%">Reorder at</th>
    <th style="width:12%">Status</th>
    <th style="width:12%">Expiry</th>
    <th style="width:10%;text-align:right">Actions</th>
  </tr>`,
  expiry: `<tr>
    <th style="width:28%">Product</th>
    <th style="width:13%">Category</th>
    <th style="width:13%">Expiry date</th>
    <th style="width:10%">Days left</th>
    <th style="width:12%">Expiry status</th>
    <th style="width:12%">Stock</th>
    <th style="width:12%;text-align:right">Actions</th>
  </tr>`,
  history: `<tr>
    <th style="width:9%">Type</th>
    <th style="width:26%">Product</th>
    <th style="width:7%">Qty</th>
    <th style="width:12%">Date</th>
    <th style="width:14%">Supplier / Reason</th>
    <th style="width:10%">By</th>
    <th style="width:22%">Remarks</th>
  </tr>`
};

/* Render: Row Builders */

/**
 * Builds a table row for the stock / low stock tab.
 * @param {Object} item
 * @returns {string}
 */
function buildStockRow(item) {
  const ss  = computeStockStatus(item);
  const pct = stockBarPercent(item);
  const bc  = stockBarClass(item);
  const es  = computeExpiryStatus(item.expiry);

  const statusLabel = { ok: 'Active', low: 'Low stock', out: 'Out of stock' };
  const badgeCls    = { ok: 'active', low: 'low', out: 'out' };

  const expiryHtml = item.expiry
    ? `<div class="expiry-tag ${es !== 'ok' && es !== 'none' ? es : ''}">
         ${es !== 'ok' && es !== 'none' ? '<i class="ti ti-alert-circle" style="font-size:13px" aria-hidden="true"></i>' : ''}
         ${item.expiry}
       </div>`
    : `<span style="color:#ddd">—</span>`;

  return `
    <tr data-id="${item.id}">
      <td>
        <div class="prod-name">${item.name}</div>
        <div class="prod-sku">${item.sku}</div>
      </td>
      <td><span class="cat-tag">${item.category}</span></td>
      <td>
        <div class="stock-wrap">
          <span class="stock-num ${ss !== 'ok' ? 'danger' : ''}">${item.stock}</span>
          <div class="bar-track" role="progressbar" aria-valuenow="${item.stock}" aria-valuemin="0" aria-valuemax="${(item.reorder || LOW_STOCK_DEFAULT) * 4}">
            <div class="bar-fill ${bc}" style="width:${pct}%"></div>
          </div>
        </div>
      </td>
      <td style="color:#888">${item.reorder || '—'}</td>
      <td><span class="badge ${badgeCls[ss]}">${statusLabel[ss]}</span></td>
      <td>${expiryHtml}</td>
      <td>
        <div class="action-btns">
          <button class="act-btn in-btn"  data-id="${item.id}" title="Stock in"  aria-label="Stock in for ${item.name}"><i class="ti ti-arrow-bar-down" aria-hidden="true"></i></button>
          <button class="act-btn out-btn" data-id="${item.id}" title="Stock out" aria-label="Stock out for ${item.name}"><i class="ti ti-arrow-bar-up" aria-hidden="true"></i></button>
        </div>
      </td>
    </tr>
  `;
}

/**
 * Builds a table row for the expiry monitoring tab.
 * @param {Object} item
 * @returns {string}
 */
function buildExpiryRow(item) {
  const es   = computeExpiryStatus(item.expiry);
  const days = daysUntilExpiry(item.expiry);
  const ss   = computeStockStatus(item);

  const daysText  = days === null ? '—' : days < 0 ? 'Expired' : `${days} days`;
  const daysStyle = days !== null && days < 0 ? 'color:#c0392b;font-weight:500'
                  : days !== null && days <= EXPIRY_WARN_DAYS ? 'color:#854f0b;font-weight:500'
                  : '';

  const badgeMap  = { expired: 'expired', expiring: 'expiring', ok: 'ok-exp' };
  const labelMap  = { expired: 'Expired', expiring: 'Expiring soon', ok: 'Good', none: '—' };

  return `
    <tr data-id="${item.id}">
      <td>
        <div class="prod-name">${item.name}</div>
        <div class="prod-sku">${item.sku}</div>
      </td>
      <td><span class="cat-tag">${item.category}</span></td>
      <td>${item.expiry || '—'}</td>
      <td style="${daysStyle}">${daysText}</td>
      <td><span class="badge ${badgeMap[es] || ''}">${labelMap[es] || '—'}</span></td>
      <td><span class="stock-num ${ss !== 'ok' ? 'danger' : ''}">${item.stock}</span></td>
      <td>
        <div class="action-btns">
          <button class="act-btn in-btn" data-id="${item.id}" title="Update stock / expiry" aria-label="Stock in for ${item.name}">
            <i class="ti ti-arrow-bar-down" aria-hidden="true"></i>
          </button>
        </div>
      </td>
    </tr>
  `;
}

/**
 * Builds a table row for the history log tab.
 * @param {Object} entry - History entry.
 * @returns {string}
 */
function buildHistoryRow(entry) {
  const prod    = inventory.find(i => i.id === entry.productId);
  const name    = prod ? prod.name : 'Unknown product';
  const sku     = prod ? prod.sku  : '';
  const typeMap = { in: 'Stock in', out: 'Stock out', adj: 'Adjustment' };
  const sign    = entry.type === 'out' ? '−' : '+';

  return `
    <tr>
      <td>
        <div style="display:flex;align-items:center;gap:6px">
          <div class="txn-dot ${entry.type}" aria-hidden="true"></div>
          <span style="font-size:12.5px">${typeMap[entry.type]}</span>
        </div>
      </td>
      <td>
        <div class="prod-name">${name}</div>
        <div class="prod-sku">${sku}</div>
      </td>
      <td style="font-weight:500">${sign}${entry.qty}</td>
      <td style="color:#888">${entry.date}</td>
      <td style="color:#888;font-size:12px">${entry.supplier || entry.reason || '—'}</td>
      <td style="color:#888">${entry.user}</td>
      <td style="color:#888;font-size:12px;white-space:normal">${entry.remarks || '—'}</td>
    </tr>
  `;
}

/* Render: Table */

/**
 * Renders the table head, body, and empty state for the active tab.
 * @param {Array} items - Paginated items.
 */
function renderTable(items) {
  const thead = document.getElementById('tableHead');
  const tbody = document.getElementById('tableTbody');
  const empty = document.getElementById('emptyState');
  if (!thead || !tbody) return;

  thead.innerHTML = TABLE_HEADS[state.activeTab] || TABLE_HEADS.stock;

  if (!items.length) {
    tbody.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }

  if (empty) empty.style.display = 'none';

  const rowBuilder = state.activeTab === 'history' ? buildHistoryRow
                   : state.activeTab === 'expiry'  ? buildExpiryRow
                   : buildStockRow;

  tbody.innerHTML = items.map(rowBuilder).join('');

  // Bind action buttons
  tbody.querySelectorAll('.in-btn').forEach(btn => {
    btn.addEventListener('click', () => openStockInModal(Number(btn.dataset.id)));
  });

  tbody.querySelectorAll('.out-btn').forEach(btn => {
    btn.addEventListener('click', () => openStockOutModal(Number(btn.dataset.id)));
  });
}

/* Render: Pagination */

function renderPagination(total, totalPages) {
  const info = document.getElementById('paginationInfo');
  const btns = document.getElementById('pageBtns');
  if (!info || !btns) return;

  if (!total) { info.textContent = ''; btns.innerHTML = ''; return; }

  const start = Math.min((state.currentPage - 1) * PAGE_SIZE + 1, total);
  const end   = Math.min(state.currentPage * PAGE_SIZE, total);
  info.textContent = `${start}–${end} of ${total}`;

  let html = `<button class="pg-btn" id="pgPrev" ${state.currentPage === 1 ? 'disabled' : ''} aria-label="Previous"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>`;

  for (let i = 1; i <= totalPages; i++) {
    if (totalPages <= 7 || i === 1 || i === totalPages || Math.abs(i - state.currentPage) <= 1)
      html += `<button class="pg-btn ${i === state.currentPage ? 'active' : ''}" data-pg="${i}" aria-current="${i === state.currentPage ? 'page' : 'false'}">${i}</button>`;
    else if (Math.abs(i - state.currentPage) === 2)
      html += `<button class="pg-btn" disabled aria-hidden="true" style="border:none;background:none;color:#ccc">…</button>`;
  }

  html += `<button class="pg-btn" id="pgNext" ${state.currentPage === totalPages ? 'disabled' : ''} aria-label="Next"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>`;

  btns.innerHTML = html;

  btns.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { state.currentPage = Number(b.dataset.pg); render(); }));
  document.getElementById('pgPrev')?.addEventListener('click', () => { if (state.currentPage > 1) { state.currentPage--; render(); } });
  document.getElementById('pgNext')?.addEventListener('click', () => { if (state.currentPage < totalPages) { state.currentPage++; render(); } });
}

/* Main Render */

/**
 * Full re-render of the inventory page.
 * Call after any state or data change.
 */
function render() {
  const { items, total, totalPages } = getPage();
  renderStats();
  renderTable(items);
  renderPagination(total, totalPages);
}

/* Tab Switching */

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => {
      b.classList.remove('active');
      b.setAttribute('aria-selected', 'false');
    });
    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
    state.activeTab   = btn.dataset.tab;
    state.currentPage = 1;
    render();
  });
});

/* Search & Filter Controls */

const searchInput = document.getElementById('searchInput');
if (searchInput) {
  let searchTimer;
  searchInput.addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.searchQ     = e.target.value.trim();
      state.currentPage = 1;
      render();
    }, 250);
  });

  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      searchInput.value = '';
      state.searchQ     = '';
      state.currentPage = 1;
      render();
    }
  });
}

const catFilter = document.getElementById('catFilter');
if (catFilter) {
  catFilter.addEventListener('change', e => {
    state.catFilter   = e.target.value;
    state.currentPage = 1;
    render();
  });
}

/* Stock In Modal */

/**
 * Populates the product dropdown in either the stock-in or stock-out modal.
 * @param {string} selectId - Element ID of the <select>.
 */
function populateProductSelect(selectId) {
  const select = document.getElementById(selectId);
  if (!select) return;
  select.innerHTML = '<option value="">Select product...</option>' +
    inventory.map(i => `<option value="${i.id}">${i.name} (${i.sku}) — Stock: ${i.stock}</option>`).join('');
}

/**
 * Updates the product info card inside the stock-in modal
 * when a product is selected.
 */
function updateStockInInfo() {
  const id   = Number(document.getElementById('siProduct')?.value);
  const item = inventory.find(i => i.id === id);
  const info = document.getElementById('siProductInfo');
  if (!info) return;

  if (item) {
    const nameEl = document.getElementById('siProductName');
    const metaEl = document.getElementById('siProductMeta');
    if (nameEl) nameEl.textContent = item.name;
    if (metaEl) metaEl.textContent = `Current stock: ${item.stock}${item.expiry ? ' · Expiry: ' + item.expiry : ''}`;
    // Pre-fill existing expiry
    const expiryEl = document.getElementById('siExpiry');
    if (expiryEl && item.expiry) expiryEl.value = item.expiry;
    info.style.display = '';
  } else {
    info.style.display = 'none';
  }
}

/**
 * Opens the Stock In modal.
 * @param {number|null} preselect - Product ID to pre-select (optional).
 */
function openStockInModal(preselect = null) {
  populateProductSelect('siProduct');
  document.getElementById('siQty').value      = 1;
  document.getElementById('siSupplier').value = '';
  document.getElementById('siExpiry').value   = '';
  document.getElementById('siRemarks').value  = '';

  const infoEl = document.getElementById('siProductInfo');
  if (infoEl) infoEl.style.display = 'none';

  if (preselect) {
    const sel = document.getElementById('siProduct');
    if (sel) { sel.value = preselect; updateStockInInfo(); }
  }

  openModal('stockInModal');
  document.getElementById('siProduct')?.focus();
}

document.getElementById('siProduct')?.addEventListener('change', updateStockInInfo);

// Qty stepper — stock in
document.getElementById('siMinus')?.addEventListener('click', () => {
  const q = document.getElementById('siQty');
  if (q) q.value = Math.max(1, Number(q.value) - 1);
});
document.getElementById('siPlus')?.addEventListener('click', () => {
  const q = document.getElementById('siQty');
  if (q) q.value = Number(q.value) + 1;
});

/**
 * Handles the confirm button in the stock-in modal.
 */
async function handleStockIn() {
  const productId = Number(document.getElementById('siProduct')?.value);
  const qty       = parseInt(document.getElementById('siQty')?.value);
  const supplier  = document.getElementById('siSupplier')?.value.trim();
  const expiry    = document.getElementById('siExpiry')?.value;
  const remarks   = document.getElementById('siRemarks')?.value.trim();

  if (!productId) { alert('Please select a product.'); return; }
  if (!qty || qty < 1) { alert('Please enter a valid quantity (minimum 1).'); return; }

  const btn = document.getElementById('siSave');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader spin" aria-hidden="true"></i> Processing...'; }

  try {
    await apiStockIn({ productId, qty, supplier, expiry, remarks });
    closeModal('stockInModal');
    await loadInventory();
  } catch (err) {
    alert(err.message || 'Stock in failed. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-arrow-bar-down" aria-hidden="true"></i> Confirm stock in'; }
  }
}

document.getElementById('siSave')?.addEventListener('click', handleStockIn);
document.getElementById('siClose')?.addEventListener('click',  () => closeModal('stockInModal'));
document.getElementById('siCancel')?.addEventListener('click', () => closeModal('stockInModal'));
document.getElementById('stockInModal')?.addEventListener('click', e => { if (e.target === document.getElementById('stockInModal')) closeModal('stockInModal'); });

/* Stock Out Modal */

/**
 * Updates the product info card inside the stock-out modal.
 */
function updateStockOutInfo() {
  const id   = Number(document.getElementById('soProduct')?.value);
  const item = inventory.find(i => i.id === id);
  const info = document.getElementById('soProductInfo');
  if (!info) return;

  if (item) {
    const nameEl = document.getElementById('soProductName');
    const metaEl = document.getElementById('soProductMeta');
    if (nameEl) nameEl.textContent = item.name;
    if (metaEl) metaEl.textContent = `Current stock: ${item.stock}`;
    info.style.display = '';
  } else {
    info.style.display = 'none';
  }
}

/**
 * Opens the Stock Out modal.
 * @param {number|null} preselect - Product ID to pre-select (optional).
 */
function openStockOutModal(preselect = null) {
  populateProductSelect('soProduct');
  document.getElementById('soQty').value     = 1;
  document.getElementById('soReason').value  = '';
  document.getElementById('soRemarks').value = '';

  const infoEl = document.getElementById('soProductInfo');
  if (infoEl) infoEl.style.display = 'none';

  if (preselect) {
    const sel = document.getElementById('soProduct');
    if (sel) { sel.value = preselect; updateStockOutInfo(); }
  }

  openModal('stockOutModal');
  document.getElementById('soProduct')?.focus();
}

document.getElementById('soProduct')?.addEventListener('change', updateStockOutInfo);

// Qty stepper — stock out
document.getElementById('soMinus')?.addEventListener('click', () => {
  const q = document.getElementById('soQty');
  if (q) q.value = Math.max(1, Number(q.value) - 1);
});
document.getElementById('soPlus')?.addEventListener('click', () => {
  const q = document.getElementById('soQty');
  if (q) q.value = Number(q.value) + 1;
});

/**
 * Handles the confirm button in the stock-out modal.
 */
async function handleStockOut() {
  const productId = Number(document.getElementById('soProduct')?.value);
  const qty       = parseInt(document.getElementById('soQty')?.value);
  const reason    = document.getElementById('soReason')?.value;
  const remarks   = document.getElementById('soRemarks')?.value.trim();

  if (!productId) { alert('Please select a product.'); return; }
  if (!qty || qty < 1) { alert('Please enter a valid quantity (minimum 1).'); return; }
  if (!reason)    { alert('Please select a reason for stock out.'); return; }

  const item = inventory.find(i => i.id === productId);
  if (item && qty > item.stock) {
    alert(`Quantity (${qty}) exceeds current stock (${item.stock}).`);
    return;
  }

  const btn = document.getElementById('soSave');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="ti ti-loader spin" aria-hidden="true"></i> Processing...'; }

  try {
    await apiStockOut({ productId, qty, reason, remarks });
    closeModal('stockOutModal');
    await loadInventory();

    // Trigger browser alert if now low/out
    const updated = inventory.find(i => i.id === productId);
    if (updated) triggerBrowserAlert(updated);

  } catch (err) {
    alert(err.message || 'Stock out failed. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="ti ti-arrow-bar-up" aria-hidden="true"></i> Confirm stock out'; }
  }
}

document.getElementById('soSave')?.addEventListener('click', handleStockOut);
document.getElementById('soClose')?.addEventListener('click',  () => closeModal('stockOutModal'));
document.getElementById('soCancel')?.addEventListener('click', () => closeModal('stockOutModal'));
document.getElementById('stockOutModal')?.addEventListener('click', e => { if (e.target === document.getElementById('stockOutModal')) closeModal('stockOutModal'); });

/* Topbar Shortcut Buttons */

document.getElementById('stockInBtn')?.addEventListener('click',  () => openStockInModal(null));
document.getElementById('stockOutBtn')?.addEventListener('click', () => openStockOutModal(null));

/* Modal Helpers */

function openModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.add('open');
  el.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (!el) return;
  el.classList.remove('open');
  el.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal('stockInModal'); closeModal('stockOutModal'); }
});

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

/* Spin Style Injection */
(function injectSpinStyle() {
  if (document.getElementById('pawpos-inv-spin')) return;
  const style = document.createElement('style');
  style.id = 'pawpos-inv-spin';
  style.textContent = '@keyframes spin { to { transform: rotate(360deg); } } .spin { display: inline-block; animation: spin 0.8s linear infinite; }';
  document.head.appendChild(style);
})();

/* Init */
loadInventory().catch(err => {
  console.error('[PAWPOS] Load inventory error:', err);
  alert(err.message || 'Unable to load inventory.');
});
