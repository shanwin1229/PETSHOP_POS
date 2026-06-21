'use strict';

RBAC.initPage({ allowed: ['Admin'], dashboard: true });

const $ = id => document.getElementById(id);
const esc = value => window.PawUI ? PawUI.escapeHtml(value) : String(value ?? '');
const NOTIFICATION_SEEN_KEY = 'pawpos_seen_notifications';
let currentNotificationKeys = [];

function renderNotifications(products, appointments) {
  const lowStock = (products || []).filter(p => Number(p.stock_qty || 0) <= Number(p.reorder_level || 0));
  const today = new Date().toISOString().slice(0, 10);
  const todayAppointments = (appointments || []).filter(a => a.date === today && !['completed', 'cancelled', 'deleted'].includes(a.status));
  const notifications = [
    ...lowStock.map(p => ({ icon: 'ti-alert-triangle', cls: Number(p.stock_qty || 0) === 0 ? 'alert' : 'warn', text: `${p.name} has ${p.stock_qty || 0} item${Number(p.stock_qty || 0) === 1 ? '' : 's'} left.`, time: 'Inventory alert' })),
    ...todayAppointments.map(a => ({ icon: 'ti-calendar-event', cls: 'appt', text: `${a.pet_name || 'Pet'} — ${a.service} at ${String(a.time || '').slice(0, 5)}.`, time: 'Today' }))
  ];
  let seen = [];
  try { seen = JSON.parse(localStorage.getItem(NOTIFICATION_SEEN_KEY) || '[]'); } catch { seen = []; }
  currentNotificationKeys = notifications.map(n => `${n.time}:${n.text}`);
  const unread = notifications.filter(n => !seen.includes(`${n.time}:${n.text}`)).length;
  const body = $('notifBody');
  const button = $('notifBtn');
  if (body) body.innerHTML = notifications.length
    ? notifications.slice(0, 20).map(n => `<div class="notif-row ${seen.includes(`${n.time}:${n.text}`) ? 'read' : ''}"><div class="notif-dot-icon ${n.cls}"><i class="ti ${n.icon}"></i></div><div><div class="notif-text">${esc(n.text)}</div><div class="notif-ts">${esc(n.time)}</div></div></div>`).join('')
    : '<div style="padding:2rem 1rem;text-align:center;color:#aaa;font-size:13px"><i class="ti ti-bell-check" style="display:block;font-size:28px;margin-bottom:8px"></i>No new notifications.</div>';
  button?.classList.toggle('has-dot', unread > 0);
  button?.setAttribute('aria-label', `Notifications (${unread} unread)`);
}

function setNotificationsOpen(open) {
  $('notifPanel')?.classList.toggle('open', open);
  $('notifBtn')?.setAttribute('aria-expanded', String(open));
  if (open && currentNotificationKeys.length) {
    localStorage.setItem(NOTIFICATION_SEEN_KEY, JSON.stringify(currentNotificationKeys.slice(0, 100)));
    $('notifBtn')?.classList.remove('has-dot');
    document.querySelectorAll('#notifBody .notif-row').forEach(row => row.classList.add('read'));
  }
}
const peso = n => '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function loadDashboard() {
  const [summary, products, appointments, sales] = await Promise.all([
    PawApi.get('reports.php?action=summary'),
    PawApi.get('products.php?limit=1000'),
    PawApi.get('appointments.php?limit=1000'),
    PawApi.get('sales.php?limit=5')
  ]);

  $('todayDate') && ($('todayDate').textContent = new Date().toLocaleDateString('en-PH', { weekday:'long', year:'numeric', month:'long', day:'numeric' }));
  $('statSales') && ($('statSales').textContent = peso(summary.sales_today?.revenue || 0));
  $('statSalesTrend') && ($('statSalesTrend').textContent = (summary.sales_today?.growth_pct ?? 0) + '%');
  $('statProducts') && ($('statProducts').textContent = (products.products || []).length);
  $('statCategories') && ($('statCategories').textContent = new Set((products.products || []).map(p => p.category).filter(Boolean)).size);
  $('statLowStock') && ($('statLowStock').textContent = summary.low_stock_count || 0);
  $('statAppts') && ($('statAppts').textContent = summary.today_appointments || 0);
  $('statApptMeta') && ($('statApptMeta').textContent = 'Today');
  $('lowStockBadge') && ($('lowStockBadge').textContent = summary.low_stock_count || 0);
  $('apptBadge') && ($('apptBadge').textContent = summary.today_appointments || 0);

  const today = new Date().toISOString().slice(0,10);
  const appts = (appointments.appointments || []).filter(a => a.date === today).slice(0,5);
  $('apptList') && ($('apptList').innerHTML = appts.length ? appts.map(a => `<div class="appt-item"><span class="appt-time">${a.time || ''}</span><div class="appt-info"><div class="appt-pet">${a.pet_name || a.pet || ''}</div><div class="appt-service">${a.service || ''}</div></div><span class="appt-badge ${a.status}">${a.status}</span></div>`).join('') : '<div style="font-size:13px;color:#bbb;padding:1rem 0;text-align:center;">No appointments today.</div>');

  const low = (products.products || []).filter(p => Number(p.stock_qty || 0) <= Number(p.reorder_level || 0)).slice(0,5);
  $('stockList') && ($('stockList').innerHTML = low.length ? low.map(p => `<div class="stock-item"><div class="stock-info"><div class="stock-name">${p.name}</div><div class="stock-cat">${p.category || ''}</div></div><div class="stock-qty">${p.stock_qty} left</div></div>`).join('') : '<div style="font-size:13px;color:#bbb;padding:1rem 0;text-align:center;">No low stock alerts.</div>');

  const txns = sales.transactions || sales.sales || [];
  $('txnList') && ($('txnList').innerHTML = txns.length ? txns.map(t => `<div class="txn-item"><div class="txn-icon"><i class="ti ti-shopping-bag"></i></div><div class="txn-info"><div class="txn-name">${t.customer || 'Walk-in'}</div><div class="txn-meta">${t.txn_no || ''}</div></div><div class="txn-right"><div class="txn-amount">${peso(t.total_amount)}</div><div class="txn-time">${t.time || ''}</div></div></div>`).join('') : '<div style="font-size:13px;color:#bbb;padding:1rem 0;text-align:center;">No transactions yet.</div>');
  renderNotifications(products.products || [], appointments.appointments || []);
}

$('notifBtn')?.addEventListener('click', () => setNotificationsOpen(!$('notifPanel')?.classList.contains('open')));
$('notifClose')?.addEventListener('click', () => setNotificationsOpen(false));
document.addEventListener('keydown', e => { if (e.key === 'Escape') setNotificationsOpen(false); });
$('sidebarToggle')?.addEventListener('click', () => $('sidebar')?.classList.toggle('open'));
$('logoutBtn')?.addEventListener('click', () => { if (confirm('Log out of PAWPOS?')) RBAC.logout(); });
loadDashboard().catch(e => alert(e.message));
