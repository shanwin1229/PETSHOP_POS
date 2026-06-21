'use strict';

RBAC.initPage({ allowed: ['Admin'] });

let suppliers = [];
let editingId = null;
let deletingId = null;
let selectedId = null;
let searchQ = '';
let statusF = '';
let sortKey = 'name-asc';
const $ = id => document.getElementById(id);
const esc = value => window.PawUI ? PawUI.escapeHtml(value) : String(value ?? '');

function mapSupplier(s) {
  return {
    id: Number(s.id),
    name: s.name || '',
    contactPerson: s.contact_person || '',
    phone: s.phone || '',
    email: s.email || '',
    address: s.address || '',
    website: s.website || '',
    paymentTerms: s.payment_terms || '',
    notes: s.notes || '',
    status: s.status || 'active',
    product_count: Number(s.product_count || 0)
  };
}
function payload() {
  return {
    name: $('fName').value.trim(),
    contact_person: $('fContact').value.trim(),
    phone: $('fPhone').value.trim(),
    email: $('fEmail').value.trim(),
    address: $('fAddress').value.trim(),
    website: $('fWebsite').value.trim(),
    payment_terms: $('fPaymentTerms').value,
    notes: $('fNotes').value.trim(),
    status: $('fStatus').value || 'active'
  };
}
async function loadSuppliers() {
  const data = await PawApi.get('suppliers.php?limit=1000');
  suppliers = (data.suppliers || []).map(mapSupplier);
  renderStats();
  renderGrid();
}
function filtered() {
  let list = [...suppliers];
  const q = searchQ.toLowerCase();
  if (q) list = list.filter(s => s.name.toLowerCase().includes(q) || s.contactPerson.toLowerCase().includes(q) || s.email.toLowerCase().includes(q));
  if (statusF) list = list.filter(s => s.status === statusF);
  list.sort((a,b) => sortKey === 'name-desc' ? b.name.localeCompare(a.name) : a.name.localeCompare(b.name));
  return list;
}
function renderStats() {
  if ($('statTotal')) $('statTotal').textContent = suppliers.length;
  if ($('statActive')) $('statActive').textContent = suppliers.filter(s => s.status === 'active').length;
  if ($('statProducts')) $('statProducts').textContent = suppliers.reduce((a,s)=>a+s.product_count,0);
  if ($('statCategories')) $('statCategories').textContent = new Set(suppliers.map(s=>s.paymentTerms).filter(Boolean)).size;
  if ($('topbarSub')) $('topbarSub').textContent = `${suppliers.length} suppliers`;
}
function card(s) {
  return `<div class="supplier-card" data-id="${s.id}">
    <div class="supplier-card-head"><div><div class="supplier-name">${esc(s.name)}</div><div class="supplier-meta">${esc(s.contactPerson || 'No contact person')}</div></div><span class="badge ${esc(s.status)}">${esc(s.status)}</span></div>
    <div class="supplier-info"><div>${esc(s.phone || '—')}</div><div>${esc(s.email || '—')}</div><div>${esc(s.paymentTerms || '—')}</div></div>
    <div class="action-btns"><button class="act-btn view-btn" data-id="${s.id}"><i class="ti ti-eye"></i></button><button class="act-btn edit-btn" data-id="${s.id}"><i class="ti ti-edit"></i></button><button class="act-btn delete-btn" data-id="${s.id}"><i class="ti ti-trash"></i></button></div>
  </div>`;
}
function renderGrid() {
  const list = filtered();
  const grid = $('suppliersGrid');
  if (!grid) return;
  grid.innerHTML = list.map(card).join('');
  if ($('emptyState')) $('emptyState').style.display = list.length ? 'none' : '';
  grid.querySelectorAll('.view-btn').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); openDrawer(Number(b.dataset.id)); }));
  grid.querySelectorAll('.edit-btn').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); openEdit(Number(b.dataset.id)); }));
  grid.querySelectorAll('.delete-btn').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); openDelete(Number(b.dataset.id)); }));
  grid.querySelectorAll('.supplier-card').forEach(c => c.addEventListener('click', () => openDrawer(Number(c.dataset.id))));
}
function resetForm() {
  ['fName','fContact','fPhone','fEmail','fAddress','fWebsite','fNotes'].forEach(id => { if ($(id)) $(id).value = ''; });
  if ($('fPaymentTerms')) $('fPaymentTerms').value = '';
  if ($('fStatus')) $('fStatus').value = 'active';
}
function openAdd() { editingId = null; resetForm(); $('supplierModalTitle').textContent = 'Add supplier'; $('supplierModal').classList.add('open'); }
function openEdit(id) {
  const s = suppliers.find(x => x.id === id);
  if (!s) return;
  editingId = id;
  $('fName').value = s.name;
  $('fContact').value = s.contactPerson;
  $('fPhone').value = s.phone;
  $('fEmail').value = s.email;
  $('fAddress').value = s.address;
  $('fWebsite').value = s.website;
  $('fPaymentTerms').value = s.paymentTerms;
  $('fNotes').value = s.notes;
  $('fStatus').value = s.status;
  $('supplierModalTitle').textContent = 'Edit supplier';
  $('supplierModal').classList.add('open');
}
function closeModal() { $('supplierModal').classList.remove('open'); editingId = null; }
async function saveSupplier() {
  const data = payload();
  if (!data.name) return alert('Supplier name is required.');
  if (editingId) await PawApi.put(`suppliers.php?id=${editingId}`, data);
  else await PawApi.post('suppliers.php', data);
  closeModal();
  await loadSuppliers();
}
function openDrawer(id) {
  const s = suppliers.find(x => x.id === id);
  if (!s || !$('supplierDrawer')) return;
  selectedId = id;
  $('drawerTitle').textContent = s.name;
  $('drawerBody').innerHTML = `<p><b>Contact:</b> ${s.contactPerson || '—'}</p><p><b>Phone:</b> ${s.phone || '—'}</p><p><b>Email:</b> ${s.email || '—'}</p><p><b>Address:</b> ${s.address || '—'}</p><p><b>Terms:</b> ${s.paymentTerms || '—'}</p>`;
  $('supplierDrawer').classList.add('open');
  $('drawerOverlay')?.classList.add('open');
}
function closeDrawer() { $('supplierDrawer')?.classList.remove('open'); $('drawerOverlay')?.classList.remove('open'); selectedId = null; }
function openDelete(id) { const s = suppliers.find(x => x.id === id); if (!s) return; deletingId = id; $('deleteMsg').textContent = `Delete ${s.name}?`; $('deleteModal').classList.add('open'); }
function closeDelete() { $('deleteModal').classList.remove('open'); deletingId = null; }
async function confirmDelete() { if (deletingId) await PawApi.delete(`suppliers.php?id=${deletingId}`); closeDelete(); await loadSuppliers(); }
function bind() {
  $('addSupplierBtn')?.addEventListener('click', openAdd);
  $('supplierModalClose')?.addEventListener('click', closeModal);
  $('supplierModalCancel')?.addEventListener('click', closeModal);
  $('supplierModalSave')?.addEventListener('click', () => saveSupplier().catch(e => alert(e.message)));
  $('deleteCancelBtn')?.addEventListener('click', closeDelete);
  $('deleteConfirmBtn')?.addEventListener('click', () => confirmDelete().catch(e => alert(e.message)));
  $('drawerClose')?.addEventListener('click', closeDrawer);
  $('drawerOverlay')?.addEventListener('click', closeDrawer);
  $('drawerEditBtn')?.addEventListener('click', () => { const id = selectedId; closeDrawer(); openEdit(id); });
  $('drawerDeleteBtn')?.addEventListener('click', () => { const id = selectedId; closeDrawer(); openDelete(id); });
  $('searchInput')?.addEventListener('input', e => { searchQ = e.target.value.trim(); renderGrid(); });
  $('statusFilter')?.addEventListener('change', e => { statusF = e.target.value; renderGrid(); });
  $('sortSelect')?.addEventListener('change', e => { sortKey = e.target.value; renderGrid(); });
  $('sidebarToggle')?.addEventListener('click', () => $('sidebar')?.classList.toggle('open'));
  $('logoutBtn')?.addEventListener('click', () => { if (confirm('Log out of PAWPOS?')) RBAC.logout(); });
}
bind();
loadSuppliers().catch(e => alert(e.message));
