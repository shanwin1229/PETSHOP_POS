

'use strict';

RBAC.initPage({ allowed: ['Admin', 'Cashier', 'Groomer'] });

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
const PAGE_SIZE         = 10;
const VACC_WARN_DAYS    = 30;   // flag as "due soon" within 30 days
const REMINDER_INTERVAL = 24 * 60 * 60 * 1000;   // 24 hours in ms

/* Owner Map  ← Mirrors Customers Data */
const OWNER_MAP = {
  1: { name: 'Juan dela Cruz',    contact: '0917-123-4567', avatarBg: '#e1f5ee', avatarFg: '#085041' },
  2: { name: 'Maria Santos',      contact: '0918-234-5678', avatarBg: '#e6f1fb', avatarFg: '#185fa5' },
  3: { name: 'Pedro Reyes',       contact: '0919-345-6789', avatarBg: '#faeeda', avatarFg: '#854f0b' },
  4: { name: 'Ana Gonzales',      contact: '0920-456-7890', avatarBg: '#f0ebfb', avatarFg: '#6c3fc5' },
  5: { name: 'Carlos Mendoza',    contact: '0921-567-8901', avatarBg: '#fbeaf0', avatarFg: '#963d5a' },
  7: { name: 'Ramon Torres',      contact: '0923-789-0123', avatarBg: '#e1f5ee', avatarFg: '#085041' },
  8: { name: 'Rosario Villanueva',contact: '0924-890-1234', avatarBg: '#faeeda', avatarFg: '#854f0b' }
};

const SPECIES_EMOJI = { Dog: '🐕', Cat: '🐱', Rabbit: '🐰', Bird: '🐦', Hamster: '🐹', Fish: '🐟', Others: '🐾' };
const SPECIES_BG    = { Dog: '#e6f1fb', Cat: '#faeeda', Rabbit: '#f0ebfb', Bird: '#e1f5ee', Hamster: '#fbeaf0', Fish: '#e6f1fb', Others: '#f5f5f5' };

let pets = [];

/* State */
const state = {
  searchQ:     '',
  speciesF:    '',
  genderF:     '',
  sortKey:     'name-asc',
  viewMode:    'grid',      // 'grid' | 'list'
  currentPage: 1,
  editingId:   null,
  deletingId:  null,
  viewingId:   null,
  vaccRows:    []           // tracks dynamic vaccination form rows
};

/* Api Layer */


function mapPet(row) {
  return {
    id: Number(row.id),
    name: row.name || '',
    species: row.species || '',
    breed: row.breed || '',
    age: row.age || '',
    gender: row.gender || '',
    color: row.color || '',
    birthdate: row.birthdate || row.birth_date || '',
    weight: Number(row.weight || 0),
    ownerId: Number(row.owner_id || row.ownerId || 0),
    owner: row.owner || row.owner_name || '',
    notes: row.medical_notes || row.notes || '',
    status: row.status || 'active',
    vaccinations: row.vaccinations || []
  };
}

function petPayload(p) {
  return {
    owner_id: p.ownerId,
    name: p.name,
    species: p.species,
    breed: p.breed,
    age: p.age,
    gender: p.gender,
    weight: p.weight,
    color: p.color,
    birthdate: p.birthdate || null,
    medical_notes: p.notes || '',
    status: p.status || 'active',
    vaccinations: Array.isArray(p.vaccinations) ? p.vaccinations : []
  };
}

async function apiGetPets() {
  const data = await PawApi.get('pets.php?limit=1000');
  return (data.pets || data.items || data || []).map(mapPet);
}

async function apiCreatePet(payload) {
  const data = await PawApi.post('pets.php', petPayload(payload));
  return mapPet(data.pet || data);
}

async function apiUpdatePet(id, payload) {
  const data = await PawApi.put(`pets.php?id=${id}`, petPayload(payload));
  return mapPet(data.pet || data);
}

async function apiDeletePet(id) {
  await PawApi.delete(`pets.php?id=${id}`);
}

async function apiUpdateVaccinations(petId, vaccinations) {
  await PawApi.put(`pets.php?id=${petId}&action=update_vacc`, { vaccinations });
}

async function loadOwners() {
  const data = await PawApi.get('customers.php?limit=1000&status=active');
  const owners = data.customers || data.items || [];
  const select = document.getElementById('fOwner');
  if (!select) return;
  select.replaceChildren(new Option('Select owner', ''));
  owners.forEach((owner, index) => {
    const id = Number(owner.id);
    const name = owner.full_name || `${owner.first_name || ''} ${owner.last_name || ''}`.trim();
    OWNER_MAP[id] = {
      name,
      contact: owner.contact || '',
      avatarBg: ['#e1f5ee','#e6f1fb','#faeeda','#f0ebfb','#fbeaf0'][index % 5],
      avatarFg: ['#085041','#185fa5','#854f0b','#6c3fc5','#963d5a'][index % 5]
    };
    select.add(new Option(name, String(id)));
  });
}

async function loadPets() {
  pets = await apiGetPets();
  state.currentPage = 1;
  render();
  triggerVaccReminders();
}

/* Vaccination Reminders */

/**
 * Computes the vaccination status of a single vaccine record.
 * @param {Object} vacc - { date, due, status }
 * @returns {'done'|'due'|'overdue'|'upcoming'}
 */
function computeVaccStatus(vacc) {
  if (!vacc.due) return vacc.status || 'due';
  const daysLeft = Math.ceil((new Date(vacc.due) - new Date()) / (1000 * 60 * 60 * 24));
  if (daysLeft < 0)              return 'overdue';
  if (daysLeft <= VACC_WARN_DAYS) return 'upcoming';
  return vacc.status === 'done' ? 'done' : 'due';
}

/**
 * Returns all vaccines across all pets that are due or overdue.
 * Used for the reminder list and badge count.
 * @returns {{ pet: Pet, vacc: Object, daysLeft: number }[]}
 */
function getDueVaccinations() {
  const results = [];
  pets.forEach(pet => {
    pet.vaccinations.forEach(vacc => {
      if (vacc.status === 'done' && computeVaccStatus(vacc) === 'done') return;
      if (!vacc.due) return;
      const daysLeft = Math.ceil((new Date(vacc.due) - new Date()) / (1000 * 60 * 60 * 24));
      if (daysLeft <= VACC_WARN_DAYS) {
        results.push({ pet, vacc, daysLeft });
      }
    });
  });
  return results.sort((a, b) => a.daysLeft - b.daysLeft);
}

/**
 * Returns a summary string for a pet's vaccination status.
 * @param {Pet} pet
 * @returns {{ label: string, cls: string, dot: string }}
 */
function vaccSummary(pet) {
  const total    = pet.vaccinations.length;
  const done     = pet.vaccinations.filter(v => v.status === 'done').length;
  const due      = pet.vaccinations.filter(v => v.status !== 'done').length;
  if (!total)  return { label: 'None recorded', cls: 'vacc-no',  dot: 'none' };
  if (due > 0) return { label: `${due} due`,    cls: 'vacc-due', dot: 'due'  };
  return { label: `${done}/${total} done`,       cls: 'vacc-ok',  dot: 'done' };
}

/**
 * Generates reminder messages for pets with upcoming vaccinations.
 * @returns {string[]} Array of reminder strings.
 */
function generateVaccReminders() {
  return getDueVaccinations().map(({ pet, vacc, daysLeft }) => {
    const owner = OWNER_MAP[pet.ownerId];
    const when  = daysLeft < 0
      ? `overdue by ${Math.abs(daysLeft)} day${Math.abs(daysLeft) !== 1 ? 's' : ''}`
      : daysLeft === 0
        ? 'due today'
        : `due in ${daysLeft} day${daysLeft !== 1 ? 's' : ''}`;
    return `${pet.name} (${pet.species}): ${vacc.name} — ${when}${owner ? '. Owner: ' + owner.name : ''}`;
  });
}

/**
 * Fires a browser Notification for each due vaccination.
 * Only runs if permission is already granted.
 * Throttled by REMINDER_INTERVAL to avoid spam.
 */
function triggerVaccReminders() {
  if (!('Notification' in window)) return;

  const lastRun = parseInt(sessionStorage.getItem('pawpos_vacc_reminder_ts') || '0', 10);
  if (Date.now() - lastRun < REMINDER_INTERVAL) return;

  if (Notification.permission !== 'granted') {
    Notification.requestPermission();
    return;
  }

  const due = getDueVaccinations().slice(0, 5);   // cap at 5 notifications
  due.forEach(({ pet, vacc, daysLeft }) => {
    const body = daysLeft < 0
      ? `Overdue by ${Math.abs(daysLeft)} days!`
      : `Due in ${daysLeft} day${daysLeft !== 1 ? 's' : ''} (${vacc.due})`;
    new Notification(`💉 Vaccination Reminder — ${pet.name}`, { body: `${vacc.name}: ${body}`, icon: '🐾' });
  });

  sessionStorage.setItem('pawpos_vacc_reminder_ts', String(Date.now()));
}

/* Filtering & Sorting */

function getFiltered() {
  let list = [...pets];

  if (state.searchQ) {
    const q = state.searchQ.toLowerCase();
    list = list.filter(p => {
      const owner = OWNER_MAP[p.ownerId];
      return p.name.toLowerCase().includes(q) ||
             p.breed.toLowerCase().includes(q) ||
             p.species.toLowerCase().includes(q) ||
             (owner && owner.name.toLowerCase().includes(q));
    });
  }

  if (state.speciesF) list = list.filter(p => p.species === state.speciesF);
  if (state.genderF)  list = list.filter(p => p.gender  === state.genderF);

  list.sort((a, b) => {
    switch (state.sortKey) {
      case 'name-asc':   return a.name.localeCompare(b.name);
      case 'name-desc':  return b.name.localeCompare(a.name);
      case 'owner-asc':  return (OWNER_MAP[a.ownerId]?.name || '').localeCompare(OWNER_MAP[b.ownerId]?.name || '');
      case 'age-asc':    return (b.birthdate || '9').localeCompare(a.birthdate || '9');
      case 'age-desc':   return (a.birthdate || '9').localeCompare(b.birthdate || '9');
      default:           return 0;
    }
  });

  return list;
}

function getPage() {
  const filtered   = getFiltered();
  const total      = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  if (state.currentPage > totalPages) state.currentPage = totalPages;
  const start = (state.currentPage - 1) * PAGE_SIZE;
  return { items: filtered.slice(start, start + PAGE_SIZE), total, totalPages };
}

/* Utility Helpers */

function todayISO() { return new Date().toISOString().split('T')[0]; }
function setText(id, text) { const el = document.getElementById(id); if (el) el.textContent = text; }

/* Render: Stat Cards */

function renderStats() {
  const dogs     = pets.filter(p => p.species === 'Dog').length;
  const cats     = pets.filter(p => p.species === 'Cat').length;
  const vaccDue  = pets.filter(p => p.vaccinations.some(v => v.status !== 'done')).length;

  setText('statTotal',    pets.length);
  setText('statDogs',     dogs);
  setText('statCats',     cats);
  setText('statVaccDue',  vaccDue);
  setText('topbarSub',    `${pets.length} registered · ${vaccDue} vaccination${vaccDue !== 1 ? 's' : ''} due`);
}

/* Render: Grid View (Cards) */

function renderGrid(filtered) {
  const grid  = document.getElementById('petsGrid');
  const empty = document.getElementById('gridEmpty');
  if (!grid) return;

  if (!filtered.length) {
    grid.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }
  if (empty) empty.style.display = 'none';

  grid.innerHTML = filtered.map(p => {
    const owner  = OWNER_MAP[p.ownerId];
    const emoji  = SPECIES_EMOJI[p.species] || '🐾';
    const bg     = SPECIES_BG[p.species]    || '#f5f5f5';
    const vs     = vaccSummary(p);
    const gEmoji = p.gender === 'Male' ? '♂' : '♀';

    return `
      <div class="pet-card" data-id="${p.id}" tabindex="0" role="button" aria-label="View ${p.name}'s profile">
        <div class="pet-card-banner" style="background:${bg}">
          ${emoji}
          <span class="pet-card-gender-dot">${gEmoji}</span>
        </div>
        <div class="pet-card-body">
          <div class="pet-card-name">${p.name}</div>
          <div class="pet-card-breed">${p.breed || p.species}</div>
          <div class="pet-card-tags">
            <span class="pet-tag species">${p.species}</span>
            <span class="pet-tag age">${p.age}</span>
            <span class="pet-tag ${p.gender.toLowerCase()}">${p.gender}</span>
          </div>
          <div class="vacc-row">
            <div class="vacc-dot ${vs.dot}" aria-hidden="true"></div>
            <span style="font-size:11px;color:#aaa">${vs.label}</span>
          </div>
          <div class="pet-card-owner">
            <i class="ti ti-user" aria-hidden="true"></i>
            ${owner ? owner.name : '—'}
          </div>
        </div>
      </div>
    `;
  }).join('');

  grid.querySelectorAll('.pet-card').forEach(card => {
    card.addEventListener('click',   () => openDrawer(Number(card.dataset.id)));
    card.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDrawer(Number(card.dataset.id)); } });
  });
}

/* Render: List View (Table) */

function renderList(filtered) {
  const tbody = document.getElementById('petsTbody');
  const empty = document.getElementById('listEmpty');
  if (!tbody) return;

  const page = filtered.slice((state.currentPage - 1) * PAGE_SIZE, state.currentPage * PAGE_SIZE);

  if (!page.length) {
    tbody.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }
  if (empty) empty.style.display = 'none';

  tbody.innerHTML = page.map(p => {
    const emoji = SPECIES_EMOJI[p.species] || '🐾';
    const bg    = SPECIES_BG[p.species]    || '#f5f5f5';
    const owner = OWNER_MAP[p.ownerId];
    const vs    = vaccSummary(p);

    return `
      <tr data-id="${p.id}" tabindex="0" role="button" aria-label="View ${p.name}">
        <td>
          <div style="display:flex;align-items:center;gap:9px">
            <div style="width:32px;height:32px;border-radius:8px;background:${bg};display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0">${emoji}</div>
            <div>
              <div style="font-weight:500;color:#111">${p.name}</div>
              <div style="font-size:11px;color:#bbb">${p.color || ''}</div>
            </div>
          </div>
        </td>
        <td style="color:#888">${p.species}</td>
        <td style="color:#888">${p.breed || '—'}</td>
        <td style="color:#888">${p.age}</td>
        <td><span class="badge ${p.gender.toLowerCase()}">${p.gender}</span></td>
        <td style="color:#888;font-size:12.5px">${owner ? owner.name : '—'}</td>
        <td><span class="badge ${vs.cls}">${vs.label}</span></td>
        <td>
          <div class="action-btns">
            <button class="act-btn edit-btn"   data-id="${p.id}" title="Edit"   aria-label="Edit ${p.name}"><i class="ti ti-edit" aria-hidden="true"></i></button>
            <button class="act-btn delete-btn" data-id="${p.id}" title="Delete" aria-label="Delete ${p.name}"><i class="ti ti-trash" aria-hidden="true"></i></button>
          </div>
        </td>
      </tr>
    `;
  }).join('');

  tbody.querySelectorAll('tr').forEach(row => {
    row.addEventListener('click', e => { if (!e.target.closest('.act-btn')) openDrawer(Number(row.dataset.id)); });
    row.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('.act-btn')) openDrawer(Number(row.dataset.id)); });
  });
  tbody.querySelectorAll('.edit-btn').forEach(b   => b.addEventListener('click', () => openEditModal(Number(b.dataset.id))));
  tbody.querySelectorAll('.delete-btn').forEach(b => b.addEventListener('click', () => openDeleteModal(Number(b.dataset.id))));
}

/* Render: Pagination */

function renderPagination(total, totalPages) {
  const info = document.getElementById('paginationInfo');
  const btns = document.getElementById('pageBtns');
  if (!info || !btns) return;

  if (!total) { info.textContent = ''; btns.innerHTML = ''; return; }
  const s = Math.min((state.currentPage - 1) * PAGE_SIZE + 1, total);
  const e = Math.min(state.currentPage * PAGE_SIZE, total);
  info.textContent = `${s}–${e} of ${total}`;

  let html = `<button class="pg-btn" id="pgPrev" ${state.currentPage === 1 ? 'disabled' : ''} aria-label="Previous page"><i class="ti ti-chevron-left" aria-hidden="true"></i></button>`;
  for (let i = 1; i <= totalPages; i++) {
    if (totalPages <= 7 || i === 1 || i === totalPages || Math.abs(i - state.currentPage) <= 1)
      html += `<button class="pg-btn ${i === state.currentPage ? 'active' : ''}" data-pg="${i}" aria-current="${i === state.currentPage ? 'page' : 'false'}">${i}</button>`;
    else if (Math.abs(i - state.currentPage) === 2)
      html += `<button class="pg-btn" disabled aria-hidden="true" style="border:none;background:none;color:#ccc">…</button>`;
  }
  html += `<button class="pg-btn" id="pgNext" ${state.currentPage === totalPages ? 'disabled' : ''} aria-label="Next page"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>`;

  btns.innerHTML = html;
  btns.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { state.currentPage = Number(b.dataset.pg); render(); }));
  document.getElementById('pgPrev')?.addEventListener('click', () => { if (state.currentPage > 1) { state.currentPage--; render(); } });
  document.getElementById('pgNext')?.addEventListener('click', () => { if (state.currentPage < totalPages) { state.currentPage++; render(); } });
}

/* Main Render */

function render() {
  const filtered = getFiltered();
  const total    = filtered.length;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));

  renderStats();
  if (state.viewMode === 'grid') renderGrid(filtered);
  else renderList(filtered);
  renderPagination(total, totalPages);
}

/* View Toggle */

document.getElementById('gridViewBtn')?.addEventListener('click', () => {
  state.viewMode = 'grid';
  document.getElementById('gridViewBtn')?.classList.add('active');
  document.getElementById('listViewBtn')?.classList.remove('active');
  document.getElementById('gridView') && (document.getElementById('gridView').style.display = '');
  document.getElementById('listView') && (document.getElementById('listView').style.display = 'none');
  render();
});

document.getElementById('listViewBtn')?.addEventListener('click', () => {
  state.viewMode = 'list';
  document.getElementById('listViewBtn')?.classList.add('active');
  document.getElementById('gridViewBtn')?.classList.remove('active');
  document.getElementById('listView') && (document.getElementById('listView').style.display = '');
  document.getElementById('gridView') && (document.getElementById('gridView').style.display = 'none');
  render();
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
    if (e.key === 'Escape') { searchInput.value = ''; state.searchQ = ''; state.currentPage = 1; render(); }
  });
}

document.getElementById('speciesFilter')?.addEventListener('change', e => { state.speciesF = e.target.value; state.currentPage = 1; render(); });
document.getElementById('genderFilter')?.addEventListener('change',  e => { state.genderF  = e.target.value; state.currentPage = 1; render(); });
document.getElementById('sortSelect')?.addEventListener('change',    e => { state.sortKey   = e.target.value; render(); });

/* Pet Detail Drawer */

function openDrawer(id) {
  const p = pets.find(x => x.id === id);
  if (!p) return;
  state.viewingId = id;

  const owner  = OWNER_MAP[p.ownerId];
  const emoji  = SPECIES_EMOJI[p.species] || '🐾';
  const bg     = SPECIES_BG[p.species]    || '#f5f5f5';

  setText('drawerTitle', `${p.name}'s Profile`);

  document.getElementById('drawerBody').innerHTML = `
    <!-- Hero -->
    <div style="text-align:center;padding:1.5rem 1rem;border-radius:var(--radius-lg);background:${bg}">
      <span style="font-size:56px;display:block;margin-bottom:8px">${emoji}</span>
      <div style="font-family:var(--font-display);font-size:22px;font-weight:500;color:#111">${p.name}</div>
      <div style="font-size:13px;color:#888;margin-top:4px">${p.breed || p.species}</div>
      <div style="display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:10px">
        <span class="pet-tag species">${p.species}</span>
        <span class="pet-tag age">${p.age}</span>
        <span class="pet-tag ${p.gender.toLowerCase()}">${p.gender}</span>
        ${p.weight ? `<span class="pet-tag" style="background:#f5f5f5;color:#666">${p.weight} kg</span>` : ''}
      </div>
    </div>

    <!-- Info grid -->
    <div>
      <div class="section-title">Pet information</div>
      <div class="info-grid">
        <div class="info-item"><div class="info-item-label">Species</div><div class="info-item-value">${p.species}</div></div>
        <div class="info-item"><div class="info-item-label">Breed</div><div class="info-item-value">${p.breed || '—'}</div></div>
        <div class="info-item"><div class="info-item-label">Age</div><div class="info-item-value">${p.age}</div></div>
        <div class="info-item"><div class="info-item-label">Gender</div><div class="info-item-value">${p.gender}</div></div>
        <div class="info-item"><div class="info-item-label">Birthdate</div><div class="info-item-value">${p.birthdate || '—'}</div></div>
        <div class="info-item"><div class="info-item-label">Weight</div><div class="info-item-value">${p.weight ? p.weight + ' kg' : '—'}</div></div>
        <div class="info-item span2"><div class="info-item-label">Color / Markings</div><div class="info-item-value">${p.color || '—'}</div></div>
      </div>
    </div>

    <!-- Owner -->
    <div>
      <div class="section-title">Owner information</div>
      ${owner ? `
        <div class="owner-card">
          <div class="owner-avatar" style="background:${owner.avatarBg};color:${owner.avatarFg}">
            ${owner.name.split(' ').map(n => n[0]).slice(0, 2).join('').toUpperCase()}
          </div>
          <div>
            <div class="owner-name">${owner.name}</div>
            <div class="owner-contact"><i class="ti ti-phone" aria-hidden="true"></i>${owner.contact}</div>
          </div>
          <a href="customers.html" style="margin-left:auto;font-size:12px;color:var(--green-accent);text-decoration:none">View profile →</a>
        </div>
      ` : '<p style="font-size:13px;color:#ccc">No owner linked.</p>'}
    </div>

    <!-- Vaccinations -->
    <div>
      <div class="section-title">Vaccination records (${p.vaccinations.length})</div>
      ${p.vaccinations.length ? `
        <div class="vacc-list">
          ${p.vaccinations.map(v => {
            const computed = computeVaccStatus(v);
            const stateClass = computed === 'done' ? 'done'
                              : computed === 'overdue' ? 'none'
                              : 'due';
            const stateLabel = { done: 'Complete', due: 'Due', upcoming: 'Due soon', overdue: 'Overdue' };
            return `
              <div class="vacc-item">
                <div class="vacc-icon-wrap ${stateClass}"><i class="ti ti-vaccine" aria-hidden="true"></i></div>
                <div class="vacc-info">
                  <div class="vacc-name">${v.name}</div>
                  <div class="vacc-date">${v.date ? 'Given: ' + v.date : 'Not yet given'} · Due: ${v.due || '—'}</div>
                </div>
                <span class="vacc-status-badge ${stateClass}">${stateLabel[computed] || computed}</span>
              </div>
            `;
          }).join('')}
        </div>
      ` : '<p style="font-size:13px;color:#ccc;padding:0.5rem 0">No vaccination records yet.</p>'}
    </div>

    <!-- Medical notes -->
    <div>
      <div class="section-title">Medical notes</div>
      <div class="notes-box ${!p.notes ? 'notes-empty' : ''}">${p.notes || 'No notes recorded.'}</div>
    </div>
  `;

  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('petDrawer').classList.add('open');
}

function closeDrawer() {
  document.getElementById('drawerOverlay')?.classList.remove('open');
  document.getElementById('petDrawer')?.classList.remove('open');
  state.viewingId = null;
}

document.getElementById('drawerClose')?.addEventListener('click', closeDrawer);
document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);
document.getElementById('drawerEditBtn')?.addEventListener('click', () => { if (state.viewingId) { closeDrawer(); openEditModal(state.viewingId); } });
document.getElementById('drawerDeleteBtn')?.addEventListener('click', () => { if (state.viewingId) { closeDrawer(); openDeleteModal(state.viewingId); } });

/* Pet Registration Form (Add / Edit) */

function computeAgeFromBirthdate(value) {
  if (!value) return '';
  const [year, month, day] = value.split('-').map(Number);
  if (!year || !month || !day) return '';
  const today = new Date();
  const birth = new Date(year, month - 1, day);
  if (birth > today) return '';

  let years = today.getFullYear() - year;
  let months = today.getMonth() - (month - 1);
  if (today.getDate() < day) months--;
  if (months < 0) { years--; months += 12; }

  if (years > 0) return `${years} year${years === 1 ? '' : 's'}${months > 0 ? ` ${months} month${months === 1 ? '' : 's'}` : ''}`;
  if (months > 0) return `${months} month${months === 1 ? '' : 's'}`;
  const days = Math.max(0, Math.floor((Date.UTC(today.getFullYear(), today.getMonth(), today.getDate()) - Date.UTC(year, month - 1, day)) / 86400000));
  return `${days} day${days === 1 ? '' : 's'}`;
}

function updateComputedAge() {
  const birthdate = document.getElementById('fBirthdate');
  const age = document.getElementById('fAge');
  if (!birthdate || !age) return;
  age.value = computeAgeFromBirthdate(birthdate.value);
}

/**
 * Reads all form field values and returns a pet payload.
 * @returns {Object}
 */
function readFormValues() {
  return {
    name:      document.getElementById('fName')?.value.trim()   || '',
    species:   document.getElementById('fSpecies')?.value       || '',
    breed:     document.getElementById('fBreed')?.value.trim()  || '',
    color:     document.getElementById('fColor')?.value.trim()  || '',
    age:       document.getElementById('fAge')?.value.trim()    || '',
    gender:    document.getElementById('fGender')?.value        || '',
    birthdate: document.getElementById('fBirthdate')?.value     || '',
    weight:    parseFloat(document.getElementById('fWeight')?.value) || null,
    ownerId:   parseInt(document.getElementById('fOwner')?.value) || null,
    notes:     document.getElementById('fNotes')?.value.trim()  || '',
    vaccinations: readVaccRows()
  };
}

/**
 * Validates pet registration form.
 * @param {Object} values
 * @returns {{ valid: boolean, message: string }}
 */
function validatePetForm(values) {
  if (!values.name)    return { valid: false, message: 'Pet name is required.' };
  if (!values.species) return { valid: false, message: 'Please select a species.' };
  if (!values.birthdate) return { valid: false, message: 'Birthdate is required so age can be computed.' };
  if (!values.age)       return { valid: false, message: 'Please enter a valid birthdate that is not in the future.' };
  if (!values.gender)  return { valid: false, message: 'Please select a gender.' };
  if (!values.ownerId) return { valid: false, message: 'Please select an owner.' };
  return { valid: true, message: '' };
}

/**
 * Clears all form fields.
 */
function clearForm() {
  ['fName','fBreed','fColor','fAge','fNotes'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  ['fBirthdate'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  const w = document.getElementById('fWeight'); if (w) w.value = '';
  const s = document.getElementById('fSpecies'); if (s) s.value = '';
  const g = document.getElementById('fGender');  if (g) g.value = '';
  const o = document.getElementById('fOwner');   if (o) o.value = '';
  clearVaccRows();
}

/**
 * Populates form for editing.
 * @param {Pet} p
 */
function populateForm(p) {
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
  set('fName',     p.name);
  set('fSpecies',  p.species);
  set('fBreed',    p.breed);
  set('fColor',    p.color);
  set('fAge',      p.age);
  set('fGender',   p.gender);
  set('fBirthdate',p.birthdate);
  updateComputedAge();
  set('fWeight',   p.weight);
  set('fOwner',    p.ownerId);
  set('fNotes',    p.notes);
  clearVaccRows();
  p.vaccinations.forEach(v => addVaccRow(v.name, v.date, v.due, v.status));
}

function openAddModal() {
  state.editingId = null;
  clearForm();
  const t = document.getElementById('petModalTitle');
  const b = document.getElementById('petModalSave');
  if (t) t.textContent = 'Add pet';
  if (b) b.innerHTML   = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save pet';
  openModal('petModal');
  document.getElementById('fName')?.focus();
}

function openEditModal(id) {
  const p = pets.find(x => x.id === id);
  if (!p) return;
  state.editingId = id;
  populateForm(p);
  const t = document.getElementById('petModalTitle');
  const b = document.getElementById('petModalSave');
  if (t) t.textContent = 'Edit pet';
  if (b) b.innerHTML   = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Update pet';
  openModal('petModal');
  document.getElementById('fName')?.focus();
}

async function handleSave() {
  const values = readFormValues();
  const { valid, message } = validatePetForm(values);
  if (!valid) { alert(message); return; }

  const saveBtn = document.getElementById('petModalSave');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="ti ti-loader spin" aria-hidden="true"></i> Saving...'; }

  try {
    if (state.editingId) {
      await apiUpdatePet(state.editingId, values);
    } else {
      await apiCreatePet(values);
    }
    closeModal('petModal');
    await loadPets();
  } catch (err) {
    console.error('[PAWPOS] Save pet error:', err);
    alert('Failed to save pet record. Please try again.');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save pet'; }
  }
}

document.getElementById('addPetBtn')?.addEventListener('click', openAddModal);
document.getElementById('petModalSave')?.addEventListener('click', handleSave);
document.getElementById('petModalClose')?.addEventListener('click',  () => closeModal('petModal'));
document.getElementById('petModalCancel')?.addEventListener('click', () => closeModal('petModal'));
document.getElementById('petModal')?.addEventListener('click', e => { if (e.target === document.getElementById('petModal')) closeModal('petModal'); });
const birthdateInput = document.getElementById('fBirthdate');
if (birthdateInput) {
  birthdateInput.max = new Date().toISOString().split('T')[0];
  birthdateInput.addEventListener('input', updateComputedAge);
  birthdateInput.addEventListener('change', updateComputedAge);
}

/* Vaccination Form Rows (Dynamic) */

let _vaccRowId = 0;

function clearVaccRows() {
  const container = document.getElementById('vaccFormRows');
  if (container) container.innerHTML = '';
  state.vaccRows = [];
}

/**
 * Adds a dynamic vaccination row to the form.
 * @param {string} name
 * @param {string} date
 * @param {string} due
 * @param {string} status
 */
function addVaccRow(name = '', date = '', due = '', status = 'done') {
  const container = document.getElementById('vaccFormRows');
  if (!container) return;

  const rowId = `vacc-row-${++_vaccRowId}`;
  state.vaccRows.push(rowId);

  const div = document.createElement('div');
  div.className    = 'vacc-form-row';
  div.dataset.rowid = rowId;
  div.style.marginBottom = '8px';
  div.innerHTML = `
    <div class="field-group">
      <label class="field-label">Vaccine name</label>
      <input class="field-input" type="text" placeholder="e.g. Rabies" value="${name}" data-field="name" />
    </div>
    <div class="field-group">
      <label class="field-label">Date given</label>
      <input class="field-input" type="date" value="${date}" data-field="date" />
    </div>
    <div class="field-group">
      <label class="field-label">Due date</label>
      <input class="field-input" type="date" value="${due}" data-field="due" />
    </div>
    <div class="field-group">
      <label class="field-label">Status</label>
      <select class="field-input" data-field="status">
        <option value="done"  ${status === 'done' ? 'selected' : ''}>Complete</option>
        <option value="due"   ${status === 'due'  ? 'selected' : ''}>Due</option>
      </select>
    </div>
    <button class="vacc-remove-btn" type="button" aria-label="Remove row">
      <i class="ti ti-x" aria-hidden="true"></i>
    </button>
  `;

  div.querySelector('.vacc-remove-btn').addEventListener('click', () => {
    div.remove();
    state.vaccRows = state.vaccRows.filter(r => r !== rowId);
  });

  container.appendChild(div);
}

/**
 * Reads all vaccination rows from the form.
 * @returns {Object[]}
 */
function readVaccRows() {
  const rows = [];
  document.querySelectorAll('#vaccFormRows .vacc-form-row').forEach(row => {
    const name   = row.querySelector('[data-field="name"]')?.value.trim();
    const date   = row.querySelector('[data-field="date"]')?.value;
    const due    = row.querySelector('[data-field="due"]')?.value;
    const status = row.querySelector('[data-field="status"]')?.value;
    if (name) rows.push({ name, date: date || '', due: due || '', status: status || 'done' });
  });
  return rows;
}

document.getElementById('addVaccRowBtn')?.addEventListener('click', () => addVaccRow());

/* Delete */

function openDeleteModal(id) {
  const p = pets.find(x => x.id === id);
  if (!p) return;
  state.deletingId = id;
  const msgEl = document.getElementById('deleteMsg');
  if (msgEl) msgEl.textContent = `"${p.name}" will be archived and hidden from active records.`;
  openModal('deleteModal');
}

async function handleDelete() {
  if (!state.deletingId) return;
  const btn = document.getElementById('deleteConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  try {
    await apiDeletePet(state.deletingId);
    closeModal('deleteModal');
    await loadPets();
  } catch (err) {
    console.error('[PAWPOS] Delete pet error:', err);
    alert('Failed to delete pet record. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
    state.deletingId = null;
  }
}

document.getElementById('deleteConfirmBtn')?.addEventListener('click', handleDelete);
document.getElementById('deleteCancelBtn')?.addEventListener('click',  () => { state.deletingId = null; closeModal('deleteModal'); });
document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === document.getElementById('deleteModal')) { state.deletingId = null; closeModal('deleteModal'); } });

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
  state.editingId = null;
}

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal('petModal'); closeModal('deleteModal'); closeDrawer(); }
});

/* Sidebar Toggle & Logout */

document.getElementById('sidebarToggle')?.addEventListener('click', () => { document.getElementById('sidebar')?.classList.toggle('open'); });
document.getElementById('logoutBtn')?.addEventListener('click', () => {
  if (!confirm('Log out of PAWPOS?')) return;
  sessionStorage.removeItem(SESSION_KEY);
  localStorage.removeItem(SESSION_KEY);
  localStorage.removeItem('pawpos_remember');
  window.location.replace('index.html');
});

/* Spin Style Injection */
(function injectSpinStyle() {
  if (document.getElementById('pawpos-pets-spin')) return;
  const style = document.createElement('style');
  style.id = 'pawpos-pets-spin';
  style.textContent = '@keyframes spin { to { transform: rotate(360deg); } } .spin { display: inline-block; animation: spin 0.8s linear infinite; }';
  document.head.appendChild(style);
})();
/* Public exports */
window.PAWPets = {
  getAll:          () => [...pets],
  getDueVacc:      getDueVaccinations,
  getReminders:    generateVaccReminders,
  vaccSummary,
  computeVaccStatus
};

/* Init */
(async function init() {
  try {
    await Promise.all([loadOwners(), loadPets()]);
  } catch (err) {
    console.error('[PAWPOS] Load pets error:', err);
    alert(err.message || 'Unable to load pet records.');
  }
})();
