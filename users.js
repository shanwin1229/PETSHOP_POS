'use strict';

RBAC.initPage({ allowed: ['Admin'] });

let users = [];
let editingId = null;
let deletingId = null;
let resetPwId = null;
let selectedRole = 'Cashier';
let searchQ = '';
let roleF = '';
let statusF = '';

const $ = id => document.getElementById(id);

function userName(u) {
  return (u.full_name || `${u.first_name || ''} ${u.last_name || ''}`.trim() || u.username || '').trim();
}
function initials(u) {
  const name = userName(u) || 'U';
  return name.split(' ').filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
}
function badgeRole(role) { return String(role || '').toLowerCase(); }
function rowUser(u) {
  const id = Number(u.id);
  const selfId = Number(RBAC.getSession?.()?.userId || 0);
  const isSelf = id === selfId;
  return `<tr>
    <td class="col-user"><div class="user-cell"><div class="u-avatar">${initials(u)}</div><div><div class="u-name">${userName(u)} ${isSelf ? '<span style="font-size:10px;color:var(--green-accent)">(you)</span>' : ''}</div><div class="u-email">@${u.username || ''}</div></div></div></td>
    <td class="col-role"><span class="badge ${badgeRole(u.role)}">${u.role || ''}</span></td>
    <td class="col-contact" style="font-size:12.5px;color:#888">${u.email || '—'}</td>
    <td class="col-status"><span class="badge ${u.status || 'active'}">${u.status || 'active'}</span></td>
    <td class="col-last" style="font-size:12px;color:#aaa">${u.last_login || '—'}</td>
    <td class="col-actions"><div class="action-btns">
      <button class="act-btn edit-btn" data-id="${id}" title="Edit"><i class="ti ti-edit"></i></button>
      <button class="act-btn pw-btn" data-id="${id}" title="Reset password"><i class="ti ti-lock-open"></i></button>
      <button class="act-btn delete-btn ${isSelf ? 'disabled-btn' : ''}" data-id="${id}" ${isSelf ? 'disabled' : ''} title="Delete"><i class="ti ti-trash"></i></button>
    </div></td>
  </tr>`;
}
function filteredUsers() {
  const q = searchQ.toLowerCase();
  return users.filter(u => {
    const hit = !q || userName(u).toLowerCase().includes(q) || String(u.username || '').toLowerCase().includes(q) || String(u.email || '').toLowerCase().includes(q);
    return hit && (!roleF || u.role === roleF) && (!statusF || u.status === statusF);
  });
}
function renderStats() {
  const active = users.filter(u => u.status !== 'deleted');
  const admins = active.filter(u => u.role === 'Admin').length;
  const cashiers = active.filter(u => u.role === 'Cashier').length;
  const groomers = active.filter(u => u.role === 'Groomer').length;
  if ($('statTotal')) $('statTotal').textContent = active.length;
  if ($('statAdmin')) $('statAdmin').textContent = admins;
  if ($('statCashier')) $('statCashier').textContent = cashiers;
  if ($('statGroomer')) $('statGroomer').textContent = groomers;
  if ($('topbarSub')) $('topbarSub').textContent = `${active.length} accounts · ${admins} admin · ${cashiers} cashier · ${groomers} groomer`;
}
function renderTable() {
  const list = filteredUsers();
  const tbody = $('userTbody');
  const empty = $('emptyState');
  if (!tbody) return;
  tbody.innerHTML = list.map(rowUser).join('');
  if (empty) empty.style.display = list.length ? 'none' : '';
  tbody.querySelectorAll('.edit-btn').forEach(b => b.addEventListener('click', () => openEdit(Number(b.dataset.id))));
  tbody.querySelectorAll('.pw-btn').forEach(b => b.addEventListener('click', () => openResetPw(Number(b.dataset.id))));
  tbody.querySelectorAll('.delete-btn:not(.disabled-btn)').forEach(b => b.addEventListener('click', () => openDelete(Number(b.dataset.id))));
}
async function loadUsers() {
  const data = await PawApi.get('users.php?limit=1000');
  users = data.users || [];
  renderStats();
  renderTable();
}
function setSelectedRole(role) {
  selectedRole = role;
  document.querySelectorAll('.role-opt').forEach(opt => opt.classList.toggle('selected', opt.dataset.role === role));
  const preview = $('permsPreview');
  if (preview) preview.innerHTML = `<div class="perm-row yes"><i class="ti ti-circle-check"></i><span>${role} access</span></div>`;
}
function resetForm() {
  ['fFirstName','fLastName','fUsername','fEmail','fContact','fPassword','fConfirm'].forEach(id => { if ($(id)) $(id).value = ''; });
  if ($('fStatus')) $('fStatus').value = 'active';
  setSelectedRole('Cashier');
}
function openAdd() {
  editingId = null;
  resetForm();
  $('userModalTitle').textContent = 'Add user';
  $('userModalSave').innerHTML = '<i class="ti ti-device-floppy"></i> Save user';
  $('userModal').classList.add('open');
}
function openEdit(id) {
  const u = users.find(x => Number(x.id) === id);
  if (!u) return;
  editingId = id;
  $('fFirstName').value = u.first_name || '';
  $('fLastName').value = u.last_name || '';
  $('fUsername').value = u.username || '';
  $('fEmail').value = u.email || '';
  if ($('fContact')) $('fContact').value = u.contact || '';
  $('fStatus').value = u.status || 'active';
  $('fPassword').value = '';
  $('fConfirm').value = '';
  setSelectedRole(u.role || 'Cashier');
  $('userModalTitle').textContent = 'Edit user';
  $('userModalSave').innerHTML = '<i class="ti ti-device-floppy"></i> Update user';
  $('userModal').classList.add('open');
}
function closeUserModal() { $('userModal').classList.remove('open'); editingId = null; }
async function saveUser() {
  const payload = {
    username: $('fUsername').value.trim(),
    first_name: $('fFirstName').value.trim(),
    last_name: $('fLastName').value.trim(),
    email: $('fEmail').value.trim(),
    role: selectedRole,
    status: $('fStatus').value || 'active'
  };
  const pw = $('fPassword').value;
  const confirm = $('fConfirm').value;
  if (!payload.first_name || !payload.last_name || !payload.username) return alert('Fill in first name, last name, and username.');
  if (!editingId && !pw) return alert('Password is required.');
  if (pw && pw !== confirm) return alert('Passwords do not match.');
  if (pw) payload.password = pw;
  if (editingId) await PawApi.put(`users.php?id=${editingId}`, payload);
  else await PawApi.post('users.php', payload);
  closeUserModal();
  await loadUsers();
}
function openResetPw(id) {
  const u = users.find(x => Number(x.id) === id);
  if (!u) return;
  resetPwId = id;
  $('resetPwInfo').innerHTML = `<div class="pw-reset-user">${userName(u)}</div><div class="pw-reset-role">@${u.username} · ${u.role}</div>`;
  $('rNewPw').value = '';
  $('rConfirmPw').value = '';
  $('resetPwModal').classList.add('open');
}
function closeResetPw() { $('resetPwModal').classList.remove('open'); resetPwId = null; }
async function saveResetPw() {
  const pw = $('rNewPw').value;
  const confirm = $('rConfirmPw').value;
  if (!pw || pw.length < 8) return alert('Password must be at least 8 characters.');
  if (pw !== confirm) return alert('Passwords do not match.');
  await PawApi.post('users.php?action=reset_password', { user_id: resetPwId, new_password: pw });
  closeResetPw();
  alert('Password updated.');
}
function openDelete(id) {
  const u = users.find(x => Number(x.id) === id);
  if (!u) return;
  deletingId = id;
  $('deleteMsg').textContent = `Delete ${userName(u)}?`;
  $('deleteModal').classList.add('open');
}
function closeDelete() { $('deleteModal').classList.remove('open'); deletingId = null; }
async function confirmDelete() {
  if (deletingId) await PawApi.delete(`users.php?id=${deletingId}`);
  closeDelete();
  await loadUsers();
}
function bind() {
  $('addUserBtn')?.addEventListener('click', openAdd);
  $('userModalClose')?.addEventListener('click', closeUserModal);
  $('userModalCancel')?.addEventListener('click', closeUserModal);
  $('userModalSave')?.addEventListener('click', () => saveUser().catch(e => alert(e.message)));
  $('resetPwClose')?.addEventListener('click', closeResetPw);
  $('resetPwCancel')?.addEventListener('click', closeResetPw);
  $('resetPwSave')?.addEventListener('click', () => saveResetPw().catch(e => alert(e.message)));
  $('deleteCancelBtn')?.addEventListener('click', closeDelete);
  $('deleteConfirmBtn')?.addEventListener('click', () => confirmDelete().catch(e => alert(e.message)));
  $('searchInput')?.addEventListener('input', e => { searchQ = e.target.value.trim(); renderTable(); });
  $('roleFilter')?.addEventListener('change', e => { roleF = e.target.value; renderTable(); });
  $('statusFilter')?.addEventListener('change', e => { statusF = e.target.value; renderTable(); });
  document.querySelectorAll('.role-opt').forEach(opt => opt.addEventListener('click', () => setSelectedRole(opt.dataset.role)));
  $('sidebarToggle')?.addEventListener('click', () => $('sidebar')?.classList.toggle('open'));
  $('logoutBtn')?.addEventListener('click', () => { if (confirm('Log out of PAWPOS?')) RBAC.logout(); });
}
bind();
loadUsers().catch(e => alert(e.message));
