'use strict';

RBAC.initPage({ allowed: ['Admin', 'Cashier'] });

let customers = [];
let editingId = null;
let deletingId = null;
let selectedId = null;
let searchQ = '';
let filterStat = '';
let sortKey = 'name-asc';
let currentPage = 1;
const PAGE_SIZE = 10;
const $ = id => document.getElementById(id);
const esc = value => window.PawUI ? PawUI.escapeHtml(value) : String(value ?? '');

function fullName(c) { return c.full_name || `${c.first_name || ''} ${c.last_name || ''}`.trim(); }
function mapCustomer(c) {
  return {
    id: Number(c.id),
    firstName: c.first_name || '',
    lastName: c.last_name || '',
    contact: c.contact || '',
    email: c.email || '',
    address: c.address || '',
    birthday: c.birthday || '',
    status: c.status || 'active',
    totalSpent: Number(c.total_spent || 0),
    joined: c.joined || '',
    pets: c.pets || [],
    transactions: c.transactions || [],
    fullName: fullName(c)
  };
}
function payload() {
  return {
    first_name: $('fFirstName').value.trim(),
    last_name: $('fLastName').value.trim(),
    contact: $('fContact').value.trim(),
    email: $('fEmail').value.trim(),
    address: $('fAddress').value.trim(),
    birthday: $('fBirthday').value || null,
    status: $('fStatus').value || 'active'
  };
}
async function loadCustomers() {
  const data = await PawApi.get('customers.php?limit=1000');
  customers = (data.customers || []).map(mapCustomer);
  renderStats();
  renderTable();
}
function peso(n) { return '₱' + Number(n || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
function renderStats() {
  const active = customers.filter(c => c.status === 'active').length;
  const revenue = customers.reduce((s, c) => s + c.totalSpent, 0);
  if ($('statTotal')) $('statTotal').textContent = customers.length;
  if ($('statActive')) $('statActive').textContent = active;
  if ($('statPets')) $('statPets').textContent = customers.reduce((s, c) => s + (c.pets?.length || 0), 0);
  if ($('statRevenue')) $('statRevenue').textContent = peso(revenue);
  if ($('topbarSub')) $('topbarSub').textContent = `${customers.length} registered · ${active} active`;
}
function filtered() {
  let list = [...customers];
  const q = searchQ.toLowerCase();
  if (q) list = list.filter(c => fullName(c).toLowerCase().includes(q) || c.contact.toLowerCase().includes(q) || c.email.toLowerCase().includes(q));
  if (filterStat) list = list.filter(c => c.status === filterStat);
  list.sort((a, b) => sortKey === 'name-desc' ? fullName(b).localeCompare(fullName(a)) : fullName(a).localeCompare(fullName(b)));
  return list;
}
function renderTable() {
  const list = filtered();
  const tbody = $('customerTbody');
  const empty = $('emptyState');
  if (!tbody) return;
  if (empty) empty.style.display = list.length ? 'none' : '';
  tbody.innerHTML = list.map(c => `<tr>
    <td><div class="user-cell"><div class="u-avatar">${esc((c.firstName[0] || '') + (c.lastName[0] || ''))}</div><div><div class="u-name">${esc(fullName(c))}</div><div class="u-email">${esc(c.email || 'No email')}</div></div></div></td>
    <td>${esc(c.contact)}</td>
    <td><span class="badge ${esc(c.status)}">${esc(c.status)}</span></td>
    <td class="money">${peso(c.totalSpent)}</td>
    <td>${esc(c.joined || '—')}</td>
    <td class="col-actions"><div class="action-btns"><button class="act-btn view-btn" data-id="${c.id}"><i class="ti ti-eye"></i></button><button class="act-btn edit-btn" data-id="${c.id}"><i class="ti ti-edit"></i></button><button class="act-btn delete-btn" data-id="${c.id}"><i class="ti ti-trash"></i></button></div></td>
  </tr>`).join('');
  tbody.querySelectorAll('.view-btn').forEach(b => b.addEventListener('click', () => openDrawer(Number(b.dataset.id))));
  tbody.querySelectorAll('.edit-btn').forEach(b => b.addEventListener('click', () => openEdit(Number(b.dataset.id))));
  tbody.querySelectorAll('.delete-btn').forEach(b => b.addEventListener('click', () => openDelete(Number(b.dataset.id))));
}
function openAdd() {
  editingId = null;
  ['fFirstName','fLastName','fContact','fEmail','fAddress','fBirthday'].forEach(id => { if ($(id)) $(id).value = ''; });
  if ($('fStatus')) $('fStatus').value = 'active';
  $('custModalTitle').textContent = 'Add customer';
  $('custModal').classList.add('open');
}
function openEdit(id) {
  const c = customers.find(x => x.id === id);
  if (!c) return;
  editingId = id;
  $('fFirstName').value = c.firstName;
  $('fLastName').value = c.lastName;
  $('fContact').value = c.contact;
  $('fEmail').value = c.email;
  $('fAddress').value = c.address;
  $('fBirthday').value = c.birthday || '';
  $('fStatus').value = c.status || 'active';
  $('custModalTitle').textContent = 'Edit customer';
  $('custModal').classList.add('open');
}
function closeModal() { $('custModal').classList.remove('open'); editingId = null; }
async function saveCustomer() {
  const data = payload();
  if (!data.first_name || !data.last_name || !data.contact) return alert('Fill in first name, last name, and contact.');
  if (editingId) await PawApi.put(`customers.php?id=${editingId}`, data);
  else await PawApi.post('customers.php', data);
  closeModal();
  await loadCustomers();
}
function openDrawer(id) {
  const c = customers.find(x => x.id === id);
  if (!c || !$('customerDrawer')) return;
  selectedId = id;
  $('drawerTitle').textContent = fullName(c);
  $('drawerBody').innerHTML = `<p><b>Contact:</b> ${c.contact}</p><p><b>Email:</b> ${c.email || '—'}</p><p><b>Address:</b> ${c.address || '—'}</p><p><b>Status:</b> ${c.status}</p>`;
  $('customerDrawer').classList.add('open');
  $('drawerOverlay')?.classList.add('open');
}
function closeDrawer() { $('customerDrawer')?.classList.remove('open'); $('drawerOverlay')?.classList.remove('open'); selectedId = null; }
function openDelete(id) { const c = customers.find(x => x.id === id); if (!c) return; deletingId = id; $('deleteMsg').textContent = `Delete ${fullName(c)}?`; $('deleteModal').classList.add('open'); }
function closeDelete() { $('deleteModal').classList.remove('open'); deletingId = null; }
async function confirmDelete() { if (deletingId) await PawApi.delete(`customers.php?id=${deletingId}`); closeDelete(); await loadCustomers(); }
function bind() {
  $('addCustomerBtn')?.addEventListener('click', openAdd);
  $('custModalClose')?.addEventListener('click', closeModal);
  $('custModalCancel')?.addEventListener('click', closeModal);
  $('custModalSave')?.addEventListener('click', () => saveCustomer().catch(e => alert(e.message)));
  $('deleteCancelBtn')?.addEventListener('click', closeDelete);
  $('deleteConfirmBtn')?.addEventListener('click', () => confirmDelete().catch(e => alert(e.message)));
  $('drawerClose')?.addEventListener('click', closeDrawer);
  $('drawerOverlay')?.addEventListener('click', closeDrawer);
  $('drawerEditBtn')?.addEventListener('click', () => { const id = selectedId; closeDrawer(); openEdit(id); });
  $('drawerDeleteBtn')?.addEventListener('click', () => { const id = selectedId; closeDrawer(); openDelete(id); });
  $('searchInput')?.addEventListener('input', e => { searchQ = e.target.value.trim(); renderTable(); });
  $('statusFilter')?.addEventListener('change', e => { filterStat = e.target.value; renderTable(); });
  $('sortSelect')?.addEventListener('change', e => { sortKey = e.target.value; renderTable(); });
  $('sidebarToggle')?.addEventListener('click', () => $('sidebar')?.classList.toggle('open'));
  $('logoutBtn')?.addEventListener('click', () => { if (confirm('Log out of PAWPOS?')) RBAC.logout(); });
}
bind();
loadCustomers().catch(e => alert(e.message));
