/* ============================================================
   Pawpos — Audit_Logs.Js
   Connects Audit_Logs.Html To Audit_Logs.Php (Live Api Data).
   ============================================================ */

'use strict';

RBAC.initPage({ allowed: ['Admin'] });

/* Session Guard (Client-Side Ux Only — Server Enforces Real Auth) */
const SESSION_KEY = 'pawpos_session';
(function sessionGuard() {
  try {
    const raw = sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(SESSION_KEY);
    const s   = raw ? JSON.parse(raw) : null;
    if (!s || !s.userId || (Date.now() - s.loginTime) >= 28800000) {
      window.location.replace('index.html');
      return;
    }
    const av = document.getElementById('userAvatar');
    const nm = document.getElementById('userDisplayName');
    const rl = document.getElementById('userDisplayRole');
    if (av) av.textContent = (s.name || 'U').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
    if (nm) nm.textContent = s.name || 'User';
    if (rl) rl.textContent = s.role || '';
  } catch { window.location.replace('index.html'); }
})();

/* State */
const $ = id => document.getElementById(id);

let isLoading = false;
let categoryMeta = [];      // from action=meta
let expandedRowId = null;   // currently expanded log id

const filters = {
  category: 'all',
  search: '',
  role: '',
  from: toDateStr(daysAgo(30)),
  to: toDateStr(new Date()),
  page: 1,
  per_page: 20
};

/* Date Helpers */
function daysAgo(n) { const d = new Date(); d.setDate(d.getDate() - n); return d; }
function toDateStr(d) { return d.toISOString().slice(0, 10); }

/* Api */

function buildQuery(params) {
  const usp = new URLSearchParams();
  Object.entries(params).forEach(([k, v]) => {
    if (v !== '' && v !== null && v !== undefined) usp.set(k, v);
  });
  return usp.toString();
}

async function apiGet(action, params = {}) {
  const qs  = buildQuery({ action, ...params });
  const res = await fetch(`audit_logs.php?${qs}`, {
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' }
  });

  if (res.status === 401) {
    sessionStorage.removeItem(SESSION_KEY);
    localStorage.removeItem(SESSION_KEY);
    window.location.replace('index.html');
    throw new Error('Not authenticated');
  }
  if (res.status === 403) {
    throw new Error('You do not have permission to view audit logs.');
  }

  const json = await res.json();
  if (!json.success) throw new Error(json.message || 'Request failed.');
  return json.data;
}

/* Stat Cards */

function renderStats(stats) {
  const grid = $('statGrid');
  if (!grid || !stats) return;
  grid.innerHTML = stats.cards.map(c => `
    <div class="kpi-card ${c.cls}">
      <div class="kpi-label"><i class="ti ${c.icon}"></i>${c.label}</div>
      <div class="kpi-value">${c.value.toLocaleString()}</div>
    </div>
  `).join('');
}

/* Filter Ui (Category Chips, Role Select, Dates) */

function renderCategoryChips() {
  const row = $('catChipRow');
  if (!row) return;

  const chips = [{ key: 'all', label: 'All', icon: 'ti-apps' }, ...categoryMeta];

  row.innerHTML = chips.map(c => `
    <button class="cat-chip ${filters.category === c.key ? 'active' : ''}" data-cat="${c.key}">
      <i class="ti ${c.icon}"></i> ${c.label}
    </button>
  `).join('');

  row.querySelectorAll('.cat-chip').forEach(btn => {
    btn.addEventListener('click', () => {
      filters.category = btn.dataset.cat;
      filters.page = 1;
      renderCategoryChips();
      loadList();
    });
  });
}

function categoryStyle(key) {
  const m = categoryMeta.find(c => c.key === key);
  return m || { label: key || 'Other', icon: 'ti-dots', bg: '#f1efe8', fg: '#5f5e5a' };
}

/* Table */

function formatTimestamp(ts) {
  const d = new Date(ts.replace(' ', 'T'));
  if (isNaN(d.getTime())) return ts;
  return d.toLocaleString('en-PH', {
    month: 'short', day: 'numeric', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: true
  });
}

function escapeHtml(str) {
  if (str === null || str === undefined) return '';
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

function renderTable(logs) {
  const body = $('logTableBody');
  if (!body) return;

  if (!logs.length) {
    body.innerHTML = `<tr><td colspan="7" class="audit-empty">No audit log entries match these filters.</td></tr>`;
    return;
  }

  body.innerHTML = logs.map(log => {
    const isExpanded = log.id === expandedRowId;
    const cat = categoryStyle(log.category);

    const row = `
      <tr class="log-row ${isExpanded ? 'expanded' : ''}" data-id="${log.id}">
        <td><i class="ti ti-chevron-right audit-expand-icon"></i></td>
        <td class="audit-time">${formatTimestamp(log.created_at)}</td>
        <td>
          <div class="audit-user-cell">
            <span class="audit-user-name">${escapeHtml(log.user_name)}</span>
            <span class="audit-user-role">${escapeHtml(log.role)}</span>
          </div>
        </td>
        <td>
          <span class="audit-cat-badge" style="background:${cat.bg};color:${cat.fg}">
            <i class="ti ${cat.icon}"></i> ${cat.label}
          </span>
        </td>
        <td>${escapeHtml(log.action)}</td>
        <td class="audit-desc">${escapeHtml(log.description || '—')}</td>
        <td class="audit-ip">${escapeHtml(log.ip_address || '—')}</td>
      </tr>`;

    if (!isExpanded) return row;

    const metaPretty = log.meta ? JSON.stringify(log.meta, null, 2) : null;

    const detail = `
      <tr class="detail-row" data-detail-for="${log.id}">
        <td colspan="7">
          <div class="audit-detail-grid">
            <div class="audit-detail-block">
              <div class="audit-detail-label">Entity</div>
              <div>${log.entity_type ? `${escapeHtml(log.entity_type)} #${log.entity_id ?? '—'}` : '—'}</div>
            </div>
            <div class="audit-detail-block">
              <div class="audit-detail-label">User Agent</div>
              <div style="font-size:11px;color:#888;word-break:break-all">${escapeHtml(log.user_agent || '—')}</div>
            </div>
            <div class="audit-detail-block">
              <div class="audit-detail-label">Full Timestamp</div>
              <div style="font-size:11px;color:#888">${escapeHtml(log.created_at)}</div>
            </div>
          </div>
          ${metaPretty ? `
            <div style="margin-top:10px">
              <div class="audit-detail-label" style="margin-bottom:5px">Change Details</div>
              <pre class="audit-detail-pre">${escapeHtml(metaPretty)}</pre>
            </div>` : ''}
        </td>
      </tr>`;

    return row + detail;
  }).join('');

  body.querySelectorAll('.log-row').forEach(tr => {
    tr.addEventListener('click', () => {
      const id = Number(tr.dataset.id);
      expandedRowId = expandedRowId === id ? null : id;
      renderTable(logs);
    });
  });
}

/* Pagination */

function renderPagination(pagination) {
  const el = $('paginationRow');
  if (!el || !pagination) return;

  const { page, per_page, total, total_pages } = pagination;
  const start = total === 0 ? 0 : (page - 1) * per_page + 1;
  const end   = Math.min(total, page * per_page);

  let pageBtns = '';
  const windowSize = 2;
  for (let p = 1; p <= total_pages; p++) {
    const inWindow = p === 1 || p === total_pages || Math.abs(p - page) <= windowSize;
    if (inWindow) {
      pageBtns += `<button class="page-btn ${p === page ? 'active' : ''}" data-page="${p}">${p}</button>`;
    } else if (pageBtns.slice(-1) !== '…') {
      pageBtns += `<span style="padding:0 2px;color:#ccc">…</span>`;
    }
  }

  el.innerHTML = `
    <span>Showing ${start}–${end} of ${total.toLocaleString()} events</span>
    <div class="pagination-btns">
      <button class="page-btn" id="prevPageBtn" ${page <= 1 ? 'disabled' : ''} aria-label="Previous page">
        <i class="ti ti-chevron-left"></i>
      </button>
      ${pageBtns}
      <button class="page-btn" id="nextPageBtn" ${page >= total_pages ? 'disabled' : ''} aria-label="Next page">
        <i class="ti ti-chevron-right"></i>
      </button>
    </div>
  `;

  $('prevPageBtn')?.addEventListener('click', () => { if (filters.page > 1) { filters.page--; loadList(); } });
  $('nextPageBtn')?.addEventListener('click', () => { if (filters.page < total_pages) { filters.page++; loadList(); } });
  el.querySelectorAll('[data-page]').forEach(btn => {
    btn.addEventListener('click', () => { filters.page = Number(btn.dataset.page); loadList(); });
  });
}

/* Load Cycles */

function setLoadingState(loading) {
  isLoading = loading;
  const icon = $('refreshBtn')?.querySelector('i');
  if (icon) icon.classList.toggle('spin', loading);
  const content = $('auditContent');
  if (content) content.style.opacity = loading ? '0.6' : '1';
}

async function loadMeta() {
  const data = await apiGet('meta');
  categoryMeta = data.categories || [];
  renderCategoryChips();
}

async function loadStats() {
  try {
    const data = await apiGet('stats');
    renderStats(data);
  } catch (err) {
    console.error('Stats load failed:', err);
  }
}

async function loadList() {
  if (isLoading) return;
  setLoadingState(true);
  expandedRowId = null;

  try {
    const data = await apiGet('list', {
      category: filters.category,
      search:   filters.search,
      role:     filters.role,
      from:     filters.from,
      to:       filters.to,
      page:     filters.page,
      per_page: filters.per_page
    });

    renderTable(data.logs);
    renderPagination(data.pagination);
    updateResultsMeta(data.pagination);
    updateLastUpdated();
  } catch (err) {
    console.error('Audit log load failed:', err);
    const body = $('logTableBody');
    if (body) body.innerHTML = `<tr><td colspan="7" class="audit-empty" style="color:#a32d2d">${escapeHtml(err.message || 'Could not load audit logs.')}</td></tr>`;
  } finally {
    setLoadingState(false);
  }
}

function updateResultsMeta(pagination) {
  const el = $('resultsMeta');
  if (!el || !pagination) return;
  el.textContent = `${pagination.total.toLocaleString()} matching event${pagination.total === 1 ? '' : 's'}`;
}

function updateLastUpdated() {
  const el = $('lastUpdated');
  if (!el) return;
  el.textContent = 'Updated ' + new Date().toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true });
}

/* Filter Controls */

let searchDebounce = null;
$('searchInput')?.addEventListener('input', (e) => {
  clearTimeout(searchDebounce);
  searchDebounce = setTimeout(() => {
    filters.search = e.target.value.trim();
    filters.page = 1;
    loadList();
  }, 350);
});

$('roleSelect')?.addEventListener('change', (e) => {
  filters.role = e.target.value;
  filters.page = 1;
  loadList();
});

function syncDateInputs() {
  if ($('fromDate')) $('fromDate').value = filters.from;
  if ($('toDate'))   $('toDate').value   = filters.to;
}

$('fromDate')?.addEventListener('change', (e) => {
  filters.from = e.target.value || filters.from;
  filters.page = 1;
  clearActiveQuickDate();
  loadList();
});

$('toDate')?.addEventListener('change', (e) => {
  filters.to = e.target.value || filters.to;
  filters.page = 1;
  clearActiveQuickDate();
  loadList();
});

function clearActiveQuickDate() {
  document.querySelectorAll('.quick-date-btn').forEach(b => b.classList.remove('active'));
}

document.querySelectorAll('.quick-date-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.quick-date-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const range = btn.dataset.range;
    const today = new Date();
    filters.to = toDateStr(today);
    filters.from = range === 'today' ? toDateStr(today) : toDateStr(daysAgo(Number(range)));

    syncDateInputs();
    filters.page = 1;
    loadList();
  });
});

$('clearFiltersBtn')?.addEventListener('click', () => {
  filters.category = 'all';
  filters.search   = '';
  filters.role     = '';
  filters.from     = toDateStr(daysAgo(30));
  filters.to       = toDateStr(new Date());
  filters.page     = 1;

  if ($('searchInput')) $('searchInput').value = '';
  if ($('roleSelect'))  $('roleSelect').value  = '';
  syncDateInputs();
  clearActiveQuickDate();
  document.querySelector('.quick-date-btn[data-range="30"]')?.classList.add('active');
  renderCategoryChips();
  loadList();
});

/* Refresh */
$('refreshBtn')?.addEventListener('click', () => {
  loadStats();
  loadList();
});

/* Export */
$('exportBtn')?.addEventListener('click', () => {
  const qs = buildQuery({
    action:   'export',
    category: filters.category,
    search:   filters.search,
    role:     filters.role,
    from:     filters.from,
    to:       filters.to
  });
  window.open(`audit_logs.php?${qs}`, '_blank');
});

/* Sidebar Toggle */
$('sidebarToggle')?.addEventListener('click', () => {
  const sb = $('sidebar');
  const open = sb?.classList.toggle('open');
  $('sidebarToggle')?.setAttribute('aria-expanded', open ? 'true' : 'false');
});

/* Logout */
$('logoutBtn')?.addEventListener('click', () => {
  if (!confirm('Log out of PAWPOS?')) return;
  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);
  localStorage.removeItem('pawpos_remember');
  window.location.replace('index.html');
});

/* Spin Style */
(function () {
  const s = document.createElement('style');
  s.textContent = '@keyframes spin{to{transform:rotate(360deg)}} .spin{display:inline-block;animation:spin 0.8s linear infinite}';
  document.head.appendChild(s);
})();

/* Init */
syncDateInputs();
(async function init() {
  try {
    await loadMeta();
  } catch (err) {
    console.error('Meta load failed:', err);
  }
  loadStats();
  loadList();
})();
