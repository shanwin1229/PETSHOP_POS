
'use strict';

const RBAC = (() => {

  const SESSION_KEY = 'pawpos_session';

  /* Role Display Labels */
  const ROLE_DISPLAY = {
    'Admin':   'Super admin (Owner)',
    'Cashier': 'Cashier',
    'Groomer': 'Groomer'
  };

  function normalizeRole(role) {
    const value = String(role || '').trim().toLowerCase();
    if (value === 'admin' || value === 'administrator') return 'Admin';
    if (value === 'cashier') return 'Cashier';
    if (value === 'groomer') return 'Groomer';
    return role;
  }

  function homeForRole(role) {
    const normalized = normalizeRole(role);
    if (normalized === 'Admin') return 'dashboard.html';
    if (normalized === 'Groomer') return 'appointments.html';
    return 'pos.html';
  }

  /* Page-Level Access (Filename → Allowed Roles) */
  const PAGE_ACCESS = {
    'dashboard.html':    ['Admin'],
    'pos.html':          ['Admin', 'Cashier'],
    'inventory.html':    ['Admin', 'Cashier'],
    'suppliers.html':    ['Admin', 'Cashier'],
    'customers.html':    ['Admin', 'Cashier', 'Groomer'],
    'pets.html':         ['Admin', 'Cashier', 'Groomer'],
    'appointments.html': ['Admin', 'Cashier', 'Groomer'],
    'reports.html':      ['Admin', 'Cashier'],
    'audit_logs.html':   ['Admin'],
    'users.html':        ['Admin'],
  };

  /* Fine-Grained Action Permissions (Action → Allowed Roles) */
  const ACTION_ACCESS = {
    // Products
    'product:add':         ['Admin'],
    'product:edit':        ['Admin'],
    'product:delete':      ['Admin'],
    // Inventory
    'inventory:adjust':    ['Admin', 'Cashier'],
    'inventory:add':       ['Admin'],
    // Suppliers
    'supplier:add':        ['Admin'],
    'supplier:edit':       ['Admin'],
    'supplier:delete':     ['Admin'],
    // Customers
    'customer:add':        ['Admin', 'Cashier'],
    'customer:edit':       ['Admin', 'Cashier'],
    'customer:delete':     ['Admin'],
    // Pets
    'pet:add':             ['Admin', 'Cashier'],
    'pet:edit':            ['Admin', 'Cashier'],
    'pet:delete':          ['Admin'],
    // Appointments
    'appt:add':            ['Admin', 'Cashier', 'Groomer'],
    'appt:edit':           ['Admin', 'Cashier', 'Groomer'],
    'appt:delete':         ['Admin'],
    // Users
    'user:add':            ['Admin'],
    'user:edit':           ['Admin'],
    'user:delete':         ['Admin'],
    'user:toggle-status':  ['Admin'],
    'user:reset-password': ['Admin'],
    // Reports
    'report:export':       ['Admin']
  };

  /* Dashboard Widgets Visible Per Role */
  const DASHBOARD_WIDGETS = {
    'Admin':   ['stat-sales', 'stat-products', 'stat-lowstock', 'stat-appts',
                'card-appts', 'card-stock', 'card-txns'],
    'Cashier': ['stat-sales', 'stat-products', 'stat-lowstock', 'stat-appts',
                'card-appts', 'card-stock', 'card-txns'],
    'Groomer': ['stat-appts', 'card-appts']
  };

  /* Session Helpers */

  function getSession() {
    try {
      const raw = sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(SESSION_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  function isSessionValid(session) {
    if (!session || !session.userId || !session.loginTime) return false;
    return (Date.now() - session.loginTime) < 8 * 60 * 60 * 1000; // 8 hours
  }

  function getRole() {
    const s = getSession();
    return s ? normalizeRole(s.role) : null;
  }

  /* Permission Checker */

  /**
   * Returns true if the current user's role is allowed to perform the action.
   * @param {string} action  e.g. 'product:add', 'appt:delete'
   * @returns {boolean}
   */
  function can(action) {
    const role    = getRole();
    const allowed = ACTION_ACCESS[action];
    return Array.isArray(allowed) ? allowed.includes(role) : false;
  }

  /* Internal — Update Sidebar User Display */

  function _updateUserDisplay(session) {
    const name        = session.name || session.username || 'User';
    const role        = session.role;
    const displayRole = ROLE_DISPLAY[role] || role;
    const initials    = name.split(' ')
                           .filter(Boolean)
                           .map(w => w[0])
                           .slice(0, 2)
                           .join('')
                           .toUpperCase() || 'U';

    // Query both by class (generic) and ID (specific pages that added IDs)
    const avatarEl = document.querySelector('.sidebar-user .user-avatar') ||
                     document.getElementById('userAvatar');
    const nameEl   = document.querySelector('.sidebar-user .user-name') ||
                     document.getElementById('userDisplayName');
    const roleEl   = document.querySelector('.sidebar-user .user-role, .sidebar-user .user-role-text') ||
                     document.getElementById('userDisplayRole');

    if (avatarEl) avatarEl.textContent = initials;
    if (nameEl)   nameEl.textContent   = name;
    if (roleEl)   roleEl.textContent   = displayRole;

    // Also update by known IDs (dashboard.html has these)
    ['userAvatar', 'userDisplayName', 'userDisplayRole'].forEach((id, i) => {
      const el  = document.getElementById(id);
      const val = [initials, name, displayRole][i];
      if (el) el.textContent = val;
    });
  }

  /* Internal — Sidebar Accordion Groups */

  function _injectSidebarStyles() {
    if (document.getElementById('rbac-nav-group-styles')) return;
    const s = document.createElement('style');
    s.id = 'rbac-nav-group-styles';
    s.textContent = [
      '.nav-section-label { display:flex; align-items:center; justify-content:space-between; }',
      '.nav-section-label.nav-group-toggle { cursor:pointer; }',
      '.nav-section-label.nav-group-toggle:hover { color:rgba(255,255,255,0.55); }',
      '.nav-chevron { font-size:10px; opacity:0.45; margin-left:auto; flex-shrink:0;',
      '  transition:transform 0.2s ease; }',
      '.nav-group-items { overflow:hidden; max-height:0;',
      '  transition:max-height 0.25s ease-out; }',
      '.nav-group-items.open { max-height:600px; }',
      '.nav-section-label.nav-group-open .nav-chevron { transform:rotate(180deg); }'
    ].join('');
    document.head.appendChild(s);
  }

  function _initSidebarGroups() {
    const nav = document.querySelector('#sidebar nav');
    if (!nav) return;

    _injectSidebarStyles();

    nav.querySelectorAll('.nav-section-label').forEach(label => {
      // Collect all immediately-following siblings until the next label
      const items = [];
      let el = label.nextElementSibling;
      while (el && !el.classList.contains('nav-section-label')) {
        items.push(el);
        el = el.nextElementSibling;
      }
      if (!items.length) return;

      // Keep "Main" section flat — no toggle
      if (label.textContent.trim().toLowerCase() === 'main') return;

      const labelHidden = label.style.display === 'none';

      // Wrap items into a collapsible container
      const group = document.createElement('div');
      group.className = 'nav-group-items';
      items[0].parentNode.insertBefore(group, items[0]);
      items.forEach(item => group.appendChild(item));

      if (labelHidden) return;

      // Add chevron icon
      const chevron = document.createElement('i');
      chevron.className = 'ti ti-chevron-down nav-chevron';
      label.appendChild(chevron);
      label.classList.add('nav-group-toggle');

      // Auto-open the group that contains the active link
      if (group.querySelector('.nav-item.active')) {
        group.classList.add('open');
        label.classList.add('nav-group-open');
      }

      label.addEventListener('click', () => {
        group.classList.toggle('open');
        label.classList.toggle('nav-group-open');
      });
    });
  }

  /* Internal — Hide Unauthorized Nav Links */

  function _updateNav(role) {
    // Hide nav items for pages the role cannot access
    document.querySelectorAll('nav .nav-item[href]').forEach(link => {
      const href     = link.getAttribute('href');
      const filename = href.replace(/^.*\//, '').replace(/[?#].*$/, '');
      const allowed  = PAGE_ACCESS[filename];
      if (allowed && !allowed.includes(role)) {
        link.style.display = 'none';
      }
    });

    // Hide section labels whose every child nav-item is hidden
    document.querySelectorAll('.nav-section-label').forEach(label => {
      let sibling    = label.nextElementSibling;
      let hasVisible = false;
      while (sibling && !sibling.classList.contains('nav-section-label')) {
        if (sibling.classList.contains('nav-item') &&
            sibling.style.display !== 'none') {
          hasVisible = true;
          break;
        }
        sibling = sibling.nextElementSibling;
      }
      label.style.display = hasVisible ? '' : 'none';
    });
  }
  /* Hide unauthorized action buttons */

  function _updateActions(role) {
    document.querySelectorAll('[data-rbac]').forEach(el => {
      const action  = el.getAttribute('data-rbac');
      const allowed = ACTION_ACCESS[action];
      if (Array.isArray(allowed) && !allowed.includes(role)) {
        el.style.display = 'none';
      }
    });
  }

  /* Internal — Filter Dashboard Widgets */

  function _filterDashboard(role) {
    const visible = DASHBOARD_WIDGETS[role] || DASHBOARD_WIDGETS['Admin'];

    // Stat cards
    [
      ['stat-sales',    'statCardSales'],
      ['stat-products', 'statCardProducts'],
      ['stat-lowstock', 'statCardLowStock'],
      ['stat-appts',    'statCardAppts']
    ].forEach(([key, id]) => {
      if (!visible.includes(key)) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      }
    });

    // Content cards
    [
      ['card-appts', 'cardAppts'],
      ['card-stock', 'cardStock'],
      ['card-txns',  'cardTxns']
    ].forEach(([key, id]) => {
      if (!visible.includes(key)) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
      }
    });

    // If stat-grid becomes sparse for groomer, collapse to single column
    if (role === 'Groomer') {
      const grid = document.querySelector('.stat-grid');
      if (grid) grid.style.gridTemplateColumns = 'minmax(0,320px)';
    }
  }
  /* initPage */

  function initPage(config) {
    const cfg        = config || {};
    const allowed    = cfg.allowed    || ['Admin', 'Cashier', 'Groomer'];
    const isDashboard = !!cfg.dashboard;

    const session = getSession();

    // Not logged in → back to login
    if (!isSessionValid(session)) {
      window.location.replace('index.html');
      return null;
    }

    const role = normalizeRole(session.role);
    session.role = role;

    // Role not permitted for this page → back to dashboard
    if (!allowed.includes(role)) {
      window.location.replace(homeForRole(role));
      return null;
    }

    _updateUserDisplay(session);
    _updateNav(role);
    _updateActions(role);
    _initSidebarGroups();

    if (isDashboard) {
      _filterDashboard(role);
    }

    return { session, role };
  }
  /* logout */

  function logout() {
    sessionStorage.removeItem(SESSION_KEY);
    localStorage.removeItem(SESSION_KEY);
    localStorage.removeItem('pawpos_remember');
    window.location.replace('index.html');
  }

  /* Expose Public Api */
  return {
    initPage,
    can,
    getSession,
    getRole,
    logout,
    ROLE_DISPLAY,
    PAGE_ACCESS,
    ACTION_ACCESS
  };

})();
