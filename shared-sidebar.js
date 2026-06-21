'use strict';

(function () {
  const SESSION_KEY = 'pawpos_session';

  const roleNames = {
    Admin: 'Admin',
    Cashier: 'Cashier',
    Groomer: 'Groomer'
  };

  const sections = [
    {
      label: 'Main',
      items: [
        { href: 'dashboard.html', icon: 'ti-layout-dashboard', text: 'Dashboard', roles: ['Admin'] },
        { href: 'pos.html', icon: 'ti-shopping-cart', text: 'Point of Sale', roles: ['Admin', 'Cashier'] }
      ]
    },
    {
      label: 'Inventory',
      items: [
        { href: 'inventory.html', icon: 'ti-building-warehouse', text: 'Inventory', roles: ['Admin', 'Cashier'] },
        { href: 'suppliers.html', icon: 'ti-truck', text: 'Suppliers', roles: ['Admin'] }
      ]
    },
    {
      label: 'Clients',
      items: [
        { href: 'customers.html', icon: 'ti-users', text: 'Customers', roles: ['Admin', 'Cashier'] },
        { href: 'pets.html', icon: 'ti-paw', text: 'Pet Records', roles: ['Admin', 'Cashier', 'Groomer'] }
      ]
    },
    {
      label: 'Grooming',
      items: [
        { href: 'appointments.html', icon: 'ti-calendar', text: 'Appointments', roles: ['Admin', 'Groomer'] }
      ]
    },
    {
      label: 'Reports',
      items: [
        { href: 'reports.html', icon: 'ti-file-text', text: 'Reports', roles: ['Admin'] },
        { href: 'audit_logs.html', icon: 'ti-shield', text: 'Audit Logs', roles: ['Admin'] }
      ]
    },
    {
      label: 'System',
      items: [
        { href: 'users.html', icon: 'ti-users-group', text: 'User Accounts', roles: ['Admin'] }
      ]
    }
  ];

  function getSession() {
    try {
      const saved = sessionStorage.getItem(SESSION_KEY) || localStorage.getItem(SESSION_KEY);
      return saved ? JSON.parse(saved) : null;
    } catch (error) {
      return null;
    }
  }

  function cleanRole(role) {
    const value = String(role || '').toLowerCase();
    if (value === 'admin' || value === 'administrator') return 'Admin';
    if (value === 'cashier') return 'Cashier';
    if (value === 'groomer') return 'Groomer';
    return 'Guest';
  }

  function initials(name) {
    return String(name || 'User')
      .split(' ')
      .filter(Boolean)
      .map(part => part[0])
      .slice(0, 2)
      .join('')
      .toUpperCase() || 'U';
  }

  function currentPage() {
    return window.location.pathname.split('/').pop() || 'index.html';
  }

  function itemHtml(item, page) {
    const active = item.href === page ? ' active' : '';
    return `
      <a class="nav-item${active}" href="${item.href}">
        <i class="ti ${item.icon}" aria-hidden="true"></i>
        <span>${item.text}</span>
      </a>`;
  }

  function sectionHtml(section, role, page) {
    const items = section.items.filter(item => item.roles.includes(role));
    if (!items.length) return '';

    return `
      <div class="nav-section-label">${section.label}</div>
      ${items.map(item => itemHtml(item, page)).join('')}`;
  }

  function renderSidebar() {
    const sidebar = document.querySelector('aside.sidebar, #sidebar');
    if (!sidebar) return;

    const session = getSession() || {};
    const role = cleanRole(session.role || 'Admin');
    const name = session.name || session.username || 'Admin User';
    const home = role === 'Admin' ? 'dashboard.html' : (role === 'Groomer' ? 'appointments.html' : 'pos.html');
    const page = currentPage();

    sidebar.id = 'sidebar';
    sidebar.classList.add('sidebar');
    sidebar.innerHTML = `
      <a class="sidebar-brand" href="${home}">
        <div class="brand-icon">🐾</div>
        <div>
          <span class="brand-name">PAWPOS</span>
          <span class="brand-sub">Pet Shop System</span>
        </div>
      </a>
      <nav>${sections.map(section => sectionHtml(section, role, page)).join('')}</nav>
      <div class="sidebar-user">
        <div class="user-avatar" id="userAvatar">${initials(name)}</div>
        <div>
          <span class="user-name" id="userDisplayName">${name}</span>
          <span class="user-role" id="userDisplayRole">${roleNames[role] || role}</span>
        </div>
        <button class="user-logout" id="logoutBtn" title="Logout" aria-label="Logout">
          <i class="ti ti-logout" aria-hidden="true"></i>
        </button>
      </div>`;

    const logoutBtn = document.getElementById('logoutBtn');
    if (logoutBtn) {
      logoutBtn.addEventListener('click', function () {
        if (!confirm('Log out of PAWPOS?')) return;
        sessionStorage.removeItem(SESSION_KEY);
        localStorage.removeItem(SESSION_KEY);
        localStorage.removeItem('pawpos_remember');
        window.location.replace('index.html');
      });
    }
  }

  renderSidebar();
  document.addEventListener('DOMContentLoaded', renderSidebar);
  window.PAWPOSSidebar = { render: renderSidebar, sections };
})();
