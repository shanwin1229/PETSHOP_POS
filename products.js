

'use strict';

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
  // Populate sidebar user info
  const el = document.getElementById('userAvatar');
  if (el) el.textContent = (session.name || 'U').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
})();

/* Constants */
const PAGE_SIZE          = 10;
const LOW_STOCK_THRESHOLD = 10;
const EXPIRY_WARN_DAYS    = 60;

const CATEGORIES = ['All'];

let products = [];

/* State */
const state = {
  searchQ:      '',
  filterCat:    'All',
  filterStatus: '',
  sortKey:      'name-asc',
  currentPage:  1,
  editingId:    null,
  deletingId:   null
};
 /* API layer */

/**
 * Fetch all products from the API.
 * @returns {Promise<Array>}
 */

function mapProduct(row) {
  return {
    id: Number(row.id),
    name: row.name || '',
    sku: row.sku || '',
    category: row.category || 'Others',
    price: Number(row.selling_price ?? row.price ?? 0),
    cost: Number(row.cost_price ?? row.cost ?? 0),
    stock: Number(row.stock_qty ?? row.stock ?? 0),
    reorder: Number(row.reorder_level ?? row.reorder ?? 0),
    expiry: row.expiry_date || row.expiry || '',
    status: row.status || 'active',
    desc: row.description || row.desc || ''
  };
}

function productPayload(p) {
  return {
    name: p.name,
    sku: p.sku,
    category: p.category,
    selling_price: p.price,
    cost_price: p.cost,
    stock_qty: p.stock,
    reorder_level: p.reorder,
    expiry_date: p.expiry || null,
    status: p.status || 'active',
    description: p.desc || ''
  };
}

async function apiGetProducts() {
  const data = await PawApi.get('products.php?limit=1000');
  return (data.products || data.items || data || []).map(mapProduct);
}

async function apiCreateProduct(payload) {
  const data = await PawApi.post('products.php', productPayload(payload));
  return mapProduct(data.product || data);
}

async function apiUpdateProduct(id, payload) {
  const data = await PawApi.put(`products.php?id=${id}`, productPayload(payload));
  return mapProduct(data.product || data);
}

async function apiDeleteProduct(id) {
  await PawApi.delete(`products.php?id=${id}`);
}

async function loadProducts() {
  products = await apiGetProducts();
  state.currentPage = 1;
  render();
}

/* Filtering, Searching & Sorting */

/**
 * Derives a product's computed status from its stock and reorder levels.
 * Manual 'inactive' status is respected.
 * @param {Object} product
 * @returns {'active'|'low'|'out'|'inactive'}
 */
function computeStatus(product) {
  if (product.status === 'inactive') return 'inactive';
  if (product.stock === 0) return 'out';
  if (product.reorder && product.stock <= product.reorder) return 'low';
  return 'active';
}

/**
 * Checks whether a product's expiry date is within the warning window.
 * @param {string} dateStr - ISO date string or empty.
 * @returns {'expired'|'expiring'|'ok'|'none'}
 */
function expiryStatus(dateStr) {
  if (!dateStr) return 'none';
  const daysLeft = Math.ceil((new Date(dateStr) - new Date()) / (1000 * 60 * 60 * 24));
  if (daysLeft < 0)               return 'expired';
  if (daysLeft <= EXPIRY_WARN_DAYS) return 'expiring';
  return 'ok';
}

/**
 * Applies all active filters and search query to the product list.
 * @param {Array} list - Full product array.
 * @returns {Array} Filtered array.
 */
function applyFilters(list) {
  let result = [...list];

  // ── Text search ──
  if (state.searchQ) {
    const q = state.searchQ.toLowerCase();
    result = result.filter(p =>
      p.name.toLowerCase().includes(q) ||
      p.sku.toLowerCase().includes(q)  ||
      p.category.toLowerCase().includes(q)
    );
  }

  // ── Category filter ──
  if (state.filterCat && state.filterCat !== 'All') {
    result = result.filter(p => p.category === state.filterCat);
  }

  // ── Status filter ──
  if (state.filterStatus) {
    result = result.filter(p => computeStatus(p) === state.filterStatus);
  }

  return result;
}

/**
 * Sorts a product list by the current sort key.
 * @param {Array} list
 * @returns {Array} Sorted array.
 */
function applySort(list) {
  return [...list].sort((a, b) => {
    switch (state.sortKey) {
      case 'name-asc':    return a.name.localeCompare(b.name);
      case 'name-desc':   return b.name.localeCompare(a.name);
      case 'price-asc':   return a.price - b.price;
      case 'price-desc':  return b.price - a.price;
      case 'stock-asc':   return a.stock - b.stock;
      case 'stock-desc':  return b.stock - a.stock;
      default:            return 0;
    }
  });
}

/**
 * Returns the filtered + sorted + paginated slice of products,
 * along with metadata for rendering.
 * @returns {{ items: Array, total: number, totalPages: number }}
 */
function getPage() {
  const filtered = applyFilters(products);
  const sorted   = applySort(filtered);
  const total    = sorted.length;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  // Clamp current page
  if (state.currentPage > totalPages) state.currentPage = totalPages;

  const start = (state.currentPage - 1) * PAGE_SIZE;
  const items = sorted.slice(start, start + PAGE_SIZE);
  return { items, total, totalPages };
}

/* Utility Helpers */

function peso(n) {
  return '₱' + Number(n).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function stockBarPct(product) {
  const cap = product.reorder ? product.reorder * 4 : 50;
  return Math.min(100, Math.round((product.stock / cap) * 100));
}

function stockBarClass(product) {
  const s = computeStatus(product);
  return s === 'out' ? 'danger' : s === 'low' ? 'warn' : 'ok';
}

/* Render: Category Pills */

/**
 * Renders clickable category filter pills.
 */
function renderCatPills() {
  const el = document.getElementById('catPills');
  if (!el) return;

  el.innerHTML = CATEGORIES.map(cat => `
    <button
      class="cat-pill ${state.filterCat === cat ? 'active' : ''}"
      data-cat="${cat}"
      aria-pressed="${state.filterCat === cat}"
    >${cat}</button>
  `).join('');

  el.querySelectorAll('.cat-pill').forEach(btn => {
    btn.addEventListener('click', () => {
      state.filterCat   = btn.dataset.cat;
      state.currentPage = 1;
      render();
    });
  });
}

/* Render: Summary Line */

function renderSummary(total) {
  const el = document.getElementById('summaryCount');
  if (!el) return;

  const isFiltered = total !== products.length;
  el.innerHTML = `Showing <strong>${total}</strong> product${total !== 1 ? 's' : ''}
    ${isFiltered ? `<span style="color:#bbb"> (filtered from ${products.length} total)</span>` : ''}`;

  const subEl = document.getElementById('productCountSub');
  if (subEl) subEl.textContent = `${products.length} products · ${CATEGORIES.length - 1} categories`;
}

/* Render: Table Rows */

/**
 * Builds the HTML for a single product table row.
 * @param {Object} p - Product object.
 * @returns {string}
 */
function buildRow(p) {
  const status    = computeStatus(p);
  const expStatus = expiryStatus(p.expiry);
  const pct       = stockBarPct(p);
  const barCls    = stockBarClass(p);

  const statusLabels = { active: 'Active', low: 'Low stock', out: 'Out of stock', inactive: 'Inactive' };

  const expiryHtml = p.expiry
    ? `<span class="${expStatus !== 'ok' ? 'expiry-warn' : ''}">${p.expiry}</span>`
    : '<span style="color:#ddd">—</span>';

  return `
    <tr data-id="${p.id}">
      <td class="col-name">
        <div class="prod-name">${p.name}</div>
        <div class="prod-sku">${p.sku}</div>
      </td>
      <td class="col-cat"><span class="cat-tag">${p.category}</span></td>
      <td class="col-price">${peso(p.price)}</td>
      <td class="col-stock">
        <div class="stock-cell">
          <span class="stock-num ${status === 'low' || status === 'out' ? 'low' : ''}">${p.stock}</span>
          <div class="stock-bar-wrap">
            <div class="stock-bar ${barCls}" style="width:${pct}%"></div>
          </div>
        </div>
      </td>
      <td class="col-status"><span class="badge ${status}">${statusLabels[status]}</span></td>
      <td class="col-expiry">${expiryHtml}</td>
      <td class="col-actions">
        <div class="action-btns">
          <button class="act-btn edit"   data-id="${p.id}" title="Edit product"   aria-label="Edit ${p.name}"><i class="ti ti-edit" aria-hidden="true"></i></button>
          <button class="act-btn delete" data-id="${p.id}" title="Delete product" aria-label="Delete ${p.name}"><i class="ti ti-trash" aria-hidden="true"></i></button>
        </div>
      </td>
    </tr>
  `;
}

/**
 * Renders the product table and empty state.
 * @param {Array} items
 */
function renderTable(items) {
  const tbody = document.getElementById('productTbody');
  const empty = document.getElementById('emptyState');
  if (!tbody) return;

  if (!items.length) {
    tbody.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }

  if (empty) empty.style.display = 'none';
  tbody.innerHTML = items.map(p => buildRow(p)).join('');

  // Bind row action buttons
  tbody.querySelectorAll('.act-btn.edit').forEach(btn => {
    btn.addEventListener('click', () => openEditModal(Number(btn.dataset.id)));
  });

  tbody.querySelectorAll('.act-btn.delete').forEach(btn => {
    btn.addEventListener('click', () => openDeleteModal(Number(btn.dataset.id)));
  });
}

/* Render: Pagination */

/**
 * Renders the pagination bar.
 * @param {number} total      - Total filtered item count.
 * @param {number} totalPages
 */
function renderPagination(total, totalPages) {
  const info = document.getElementById('paginationInfo');
  const btns = document.getElementById('pageBtns');
  if (!info || !btns) return;

  if (!total) { info.textContent = ''; btns.innerHTML = ''; return; }

  const start = Math.min((state.currentPage - 1) * PAGE_SIZE + 1, total);
  const end   = Math.min(state.currentPage * PAGE_SIZE, total);
  info.textContent = `${start}–${end} of ${total}`;

  let html = `<button class="pg-btn" id="pgPrev" ${state.currentPage === 1 ? 'disabled' : ''} aria-label="Previous page">
    <i class="ti ti-chevron-left" aria-hidden="true"></i></button>`;

  for (let i = 1; i <= totalPages; i++) {
    if (totalPages <= 7 || i === 1 || i === totalPages || Math.abs(i - state.currentPage) <= 1) {
      html += `<button class="pg-btn ${i === state.currentPage ? 'active' : ''}" data-pg="${i}" aria-label="Page ${i}" aria-current="${i === state.currentPage ? 'page' : 'false'}">${i}</button>`;
    } else if (Math.abs(i - state.currentPage) === 2) {
      html += `<button class="pg-btn" disabled aria-hidden="true" style="border:none;background:none;color:#ccc">…</button>`;
    }
  }

  html += `<button class="pg-btn" id="pgNext" ${state.currentPage === totalPages ? 'disabled' : ''} aria-label="Next page">
    <i class="ti ti-chevron-right" aria-hidden="true"></i></button>`;

  btns.innerHTML = html;

  btns.querySelectorAll('[data-pg]').forEach(btn => {
    btn.addEventListener('click', () => {
      state.currentPage = Number(btn.dataset.pg);
      render();
    });
  });

  const prev = document.getElementById('pgPrev');
  const next = document.getElementById('pgNext');
  if (prev) prev.addEventListener('click', () => { if (state.currentPage > 1) { state.currentPage--; render(); } });
  if (next) next.addEventListener('click', () => { if (state.currentPage < totalPages) { state.currentPage++; render(); } });
}

/* Main Render Function */

/**
 * Full re-render of the product list UI.
 * Called after any state change.
 */
function render() {
  const { items, total, totalPages } = getPage();
  renderCatPills();
  renderSummary(total);
  renderTable(items);
  renderPagination(total, totalPages);
}

/* Search */

const searchInput = document.getElementById('searchInput');
if (searchInput) {
  // Debounce: wait 250ms after typing stops before filtering
  let searchTimer;
  searchInput.addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
      state.searchQ     = e.target.value.trim();
      state.currentPage = 1;
      render();
    }, 250);
  });

  // Clear on Escape
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      searchInput.value = '';
      state.searchQ     = '';
      state.currentPage = 1;
      render();
    }
  });
}

/* Filter & Sort Controls */

const statusFilter = document.getElementById('statusFilter');
if (statusFilter) {
  statusFilter.addEventListener('change', e => {
    state.filterStatus = e.target.value;
    state.currentPage  = 1;
    render();
  });
}

const sortSelect = document.getElementById('sortSelect');
if (sortSelect) {
  sortSelect.addEventListener('change', e => {
    state.sortKey = e.target.value;
    render();
  });
}

/* Add / Edit Modal */

/**
 * Reads all form field values and returns a product payload object.
 * @returns {Object}
 */
function readFormValues() {
  return {
    name:     document.getElementById('fName').value.trim(),
    sku:      document.getElementById('fSku').value.trim(),
    category: document.getElementById('fCategory').value,
    price:    parseFloat(document.getElementById('fPrice').value)  || 0,
    cost:     parseFloat(document.getElementById('fCost').value)   || 0,
    stock:    parseInt(document.getElementById('fStock').value)    || 0,
    reorder:  parseInt(document.getElementById('fReorder').value)  || 0,
    expiry:   document.getElementById('fExpiry').value,
    status:   document.getElementById('fStatus').value,
    desc:     document.getElementById('fDesc').value.trim()
  };
}

/**
 * Validates the product form.
 * @param {Object} values - From readFormValues().
 * @returns {{ valid: boolean, message: string }}
 */
function validateProductForm(values) {
  if (!values.name)     return { valid: false, message: 'Product name is required.' };
  if (!values.category) return { valid: false, message: 'Please select a category.' };
  if (values.price < 0) return { valid: false, message: 'Price cannot be negative.' };
  if (values.stock < 0) return { valid: false, message: 'Stock cannot be negative.' };
  if (isNaN(values.price)) return { valid: false, message: 'Please enter a valid price.' };
  return { valid: true, message: '' };
}

/**
 * Clears all modal form fields to their defaults.
 */
function clearForm() {
  ['fName', 'fSku', 'fDesc'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  ['fPrice', 'fCost', 'fStock', 'fReorder'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const expiry   = document.getElementById('fExpiry');
  const category = document.getElementById('fCategory');
  const status   = document.getElementById('fStatus');
  if (expiry)   expiry.value   = '';
  if (category) category.value = '';
  if (status)   status.value   = 'active';
}

/**
 * Populates the form fields with an existing product's data for editing.
 * @param {Object} product
 */
function populateForm(product) {
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
  set('fName',     product.name);
  set('fSku',      product.sku);
  set('fCategory', product.category);
  set('fPrice',    product.price);
  set('fCost',     product.cost);
  set('fStock',    product.stock);
  set('fReorder',  product.reorder);
  set('fExpiry',   product.expiry);
  set('fStatus',   product.status === 'low' || product.status === 'out' ? 'active' : product.status);
  set('fDesc',     product.desc);
}

/**
 * Opens the modal in Add mode.
 */
function openAddModal() {
  state.editingId = null;
  clearForm();
  const title = document.getElementById('modalTitle');
  const save  = document.getElementById('modalSave');
  if (title) title.textContent = 'Add product';
  if (save)  save.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save product';
  openModal('productModal');
  document.getElementById('fName')?.focus();
}

/**
 * Opens the modal in Edit mode, pre-populated with the product's data.
 * @param {number} id
 */
function openEditModal(id) {
  const product = products.find(p => p.id === id);
  if (!product) return;
  state.editingId = id;
  populateForm(product);
  const title = document.getElementById('modalTitle');
  const save  = document.getElementById('modalSave');
  if (title) title.textContent = 'Edit product';
  if (save)  save.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Update product';
  openModal('productModal');
  document.getElementById('fName')?.focus();
}

/**
 * Handles the Save button click in the add/edit modal.
 */
async function handleSave() {
  const values = readFormValues();
  const { valid, message } = validateProductForm(values);
  if (!valid) { alert(message); return; }

  const saveBtn = document.getElementById('modalSave');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="ti ti-loader spin" aria-hidden="true"></i> Saving...'; }

  try {
    if (state.editingId) {
      await apiUpdateProduct(state.editingId, values);
    } else {
      await apiCreateProduct(values);
    }
    closeModal('productModal');
    await loadProducts();
  } catch (err) {
    console.error('[PAWPOS] Save product error:', err);
    alert('Failed to save product. Please try again.');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save product'; }
  }
}

// ── Bind add / edit / save ──
document.getElementById('addProductBtn')?.addEventListener('click', openAddModal);
document.getElementById('modalSave')?.addEventListener('click', handleSave);
document.getElementById('modalClose')?.addEventListener('click',   () => closeModal('productModal'));
document.getElementById('modalCancel')?.addEventListener('click',  () => closeModal('productModal'));
document.getElementById('productModal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('productModal')) closeModal('productModal');
});

/* Delete Modal */

/**
 * Opens the delete confirmation modal for a product.
 * @param {number} id
 */
function openDeleteModal(id) {
  const product = products.find(p => p.id === id);
  if (!product) return;
  state.deletingId = id;
  const msg = document.getElementById('deleteMsg');
  if (msg) msg.textContent = `"${product.name}" will be archived and hidden from active records.`;
  openModal('deleteModal');
}

/**
 * Handles confirmed deletion.
 */
async function handleDelete() {
  if (!state.deletingId) return;

  const btn = document.getElementById('deleteConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }

  try {
    await apiDeleteProduct(state.deletingId);
    closeModal('deleteModal');
    await loadProducts();
  } catch (err) {
    console.error('[PAWPOS] Delete product error:', err);
    alert('Failed to delete product. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
    state.deletingId = null;
  }
}

document.getElementById('deleteConfirmBtn')?.addEventListener('click', handleDelete);
document.getElementById('deleteCancelBtn')?.addEventListener('click',  () => { state.deletingId = null; closeModal('deleteModal'); });
document.getElementById('deleteModal')?.addEventListener('click', e => {
  if (e.target === document.getElementById('deleteModal')) { state.deletingId = null; closeModal('deleteModal'); }
});

/* Modal Helpers */

/**
 * Opens a modal overlay by ID.
 * @param {string} modalId
 */
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  modal.classList.add('open');
  modal.setAttribute('aria-hidden', 'false');
  document.body.style.overflow = 'hidden';
}

/**
 * Closes a modal overlay by ID.
 * @param {string} modalId
 */
function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (!modal) return;
  modal.classList.remove('open');
  modal.setAttribute('aria-hidden', 'true');
  document.body.style.overflow = '';
  state.editingId = null;
}

// ── Close all modals on Escape ──
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeModal('productModal');
    closeModal('deleteModal');
  }
});

/* Spin Animation Injection */
(function injectSpinStyle() {
  if (document.getElementById('pawpos-products-spin')) return;
  const style = document.createElement('style');
  style.id = 'pawpos-products-spin';
  style.textContent = '@keyframes spin { to { transform: rotate(360deg); } } .spin { display: inline-block; animation: spin 0.8s linear infinite; }';
  document.head.appendChild(style);
})();

/* Sidebar Toggle & Logout */

document.getElementById('sidebarToggle')?.addEventListener('click', () => {
  const sidebar = document.getElementById('sidebar');
  if (sidebar) sidebar.classList.toggle('open');
});

document.getElementById('logoutBtn')?.addEventListener('click', () => {
  if (!confirm('Log out of PAWPOS?')) return;
  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);
  localStorage.removeItem('pawpos_remember');
  window.location.replace('index.html');
});

/* Init */
loadProducts().catch(err => {
  console.error('[PAWPOS] Load products error:', err);
  alert(err.message || 'Unable to load products.');
});
