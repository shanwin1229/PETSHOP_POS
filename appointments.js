

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
const TODAY_ISO         = new Date().toISOString().split('T')[0];
const MONTHS            = ['January','February','March','April','May','June','July','August','September','October','November','December'];
const DAYS_SHORT        = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
const STATUS_LABELS     = { pending:'Pending', confirmed:'Confirmed', completed:'Completed', cancelled:'Cancelled' };
const STATUS_NEXT       = { pending:'confirmed', confirmed:'completed', completed:'completed', cancelled:'cancelled' };

/* Pet & Display Helpers */
const PET_EMOJI = { 1:'🐕',2:'🐩',3:'🐱',4:'🦺',5:'🐕',6:'🐩',7:'🐕',8:'🐱',9:'🦺',10:'🐱',11:'🐰' };
const PET_BG    = { 1:'#e6f1fb',2:'#faeeda',3:'#faeeda',4:'#e6f1fb',5:'#e6f1fb',6:'#faeeda',7:'#e6f1fb',8:'#faeeda',9:'#e6f1fb',10:'#faeeda',11:'#f0ebfb' };

let appointments = [];
let appointmentPets = [];

/** Returns an ISO date string offset by N days from today. */
function offsetDate(n) {
  const d = new Date();
  d.setDate(d.getDate() + n);
  return d.toISOString().split('T')[0];
}

/* State */
const state = {
  activeTab:   'calendar',  
  calYear:     new Date().getFullYear(),
  calMonth:    new Date().getMonth(),
  searchQ:     '',
  statusF:     '',
  groomerF:    '',
  currentPage: 1,
  editingId:   null,
  deletingId:  null,
  viewingId:   null,
  dayModalDate:null
};

/* Api Layer */


function mapAppointment(row) {
  return {
    id: Number(row.id),
    petId: Number(row.pet_id || row.petId || 0),
    petName: row.pet_name || row.petName || '',
    owner: row.owner || row.owner_name || '',
    service: row.service || '',
    groomer: row.groomer || '',
    date: row.date || '',
    time: String(row.time || '').slice(0, 5),
    duration: row.duration || '1 hour',
    status: row.status || 'pending',
    notes: row.notes || ''
  };
}

function appointmentPayload(a) {
  return {
    pet_id: a.petId,
    service: a.service,
    groomer: a.groomer,
    date: a.date,
    time: a.time,
    duration: a.duration || '1 hour',
    status: a.status || 'pending',
    notes: a.notes || ''
  };
}

async function apiGetAppointments() {
  const data = await PawApi.get('appointments.php?limit=1000');
  return (data.appointments || data.items || data || []).map(mapAppointment);
}

async function apiCreateAppointment(payload) {
  const data = await PawApi.post('appointments.php', appointmentPayload(payload));
  return mapAppointment(data.appointment || data);
}

async function apiUpdateAppointment(id, payload) {
  const data = await PawApi.put(`appointments.php?id=${id}`, appointmentPayload(payload));
  return mapAppointment(data.appointment || data);
}

async function apiUpdateStatus(id, status) {
  await PawApi.patch(`appointments.php?id=${id}`, { status });
}

async function apiDeleteAppointment(id) {
  await PawApi.delete(`appointments.php?id=${id}`);
}

function populateAppointmentPets() {
  const petSelect = document.getElementById('fPet');
  const ownerSelect = document.getElementById('fOwner');
  if (petSelect) {
    petSelect.replaceChildren(new Option('Select pet', ''));
    appointmentPets.forEach(pet => petSelect.add(new Option(
      `${pet.name}${pet.breed ? ` (${pet.breed})` : ''}`, String(pet.id)
    )));
  }
  if (ownerSelect) {
    ownerSelect.replaceChildren(new Option('Auto-filled from pet', ''));
    [...new Map(appointmentPets.map(p => [p.ownerId, p.owner])).entries()]
      .filter(([id, name]) => id && name)
      .forEach(([id, name]) => ownerSelect.add(new Option(name, String(id))));
    ownerSelect.disabled = true;
  }
}

function syncAppointmentOwner() {
  const petId = Number(document.getElementById('fPet')?.value);
  const pet = appointmentPets.find(item => item.id === petId);
  const ownerSelect = document.getElementById('fOwner');
  if (ownerSelect) ownerSelect.value = pet ? String(pet.ownerId) : '';
}

async function loadAppointments() {
  const [apptRows, petData] = await Promise.all([apiGetAppointments(), PawApi.get('pets.php?limit=1000')]);
  appointments = apptRows;
  appointmentPets = (petData.pets || petData.items || []).map(p => ({
    id: Number(p.id), name: p.name || '', breed: p.breed || '',
    ownerId: Number(p.owner_id || 0), owner: p.owner_name || p.owner || ''
  }));
  populateAppointmentPets();
  renderStats();
  if (state.activeTab === 'calendar') renderCalendar();
  else { renderList(); renderPagination(); }
}

/* Appointment Scheduling Helpers */

/**
 * Checks whether a proposed time slot conflicts with an existing appointment.
 * A conflict occurs when the same groomer is booked within the duration window.
 * @param {string} groomer
 * @param {string} date
 * @param {string} time       - "HH:MM"
 * @param {string} duration   - e.g. "1 hour", "30 mins", "2.5 hours"
 * @param {number} [excludeId] - Appointment ID to exclude (for edits)
 * @returns {{ conflict: boolean, message: string }}
 */
function checkScheduleConflict(groomer, date, time, duration, excludeId = null) {
  const startMins = timeToMinutes(time);
  const durationMins = parseDuration(duration);
  const endMins = startMins + durationMins;

  const conflict = appointments.find(a => {
    if (a.id === excludeId) return false;
    if (a.groomer !== groomer) return false;
    if (a.date !== date) return false;
    if (['cancelled', 'completed'].includes(a.status)) return false;

    const aStart = timeToMinutes(a.time);
    const aEnd   = aStart + parseDuration(a.duration);

    // Overlap check: [startMins, endMins) overlaps [aStart, aEnd)
    return startMins < aEnd && endMins > aStart;
  });

  if (conflict) {
    return {
      conflict: true,
      message:  `${groomer} already has "${conflict.petName}" scheduled at ${conflict.time} on ${date}.`
    };
  }

  return { conflict: false, message: '' };
}

/**
 * Converts a "HH:MM" string to total minutes since midnight.
 * @param {string} time
 * @returns {number}
 */
function timeToMinutes(time) {
  const [h, m] = time.split(':').map(Number);
  return h * 60 + (m || 0);
}

/**
 * Parses a duration string to minutes.
 * Supports: "30 mins", "1 hour", "1.5 hours", "2.5 hours"
 * @param {string} str
 * @returns {number}
 */
function parseDuration(str) {
  if (!str) return 60;
  if (str.includes('min')) return parseInt(str) || 30;
  const hours = parseFloat(str);
  return isNaN(hours) ? 60 : Math.round(hours * 60);
}

/**
 * Returns appointments scheduled for a specific date.
 * @param {string} dateISO
 * @returns {Appointment[]}
 */
function getAppointmentsByDate(dateISO) {
  return appointments
    .filter(a => a.date === dateISO)
    .sort((a, b) => a.time.localeCompare(b.time));
}

/**
 * Returns today's appointments sorted by time.
 * @returns {Appointment[]}
 */
function getTodayAppointments() {
  return getAppointmentsByDate(TODAY_ISO);
}

/* Status Updates */

/**
 * Updates an appointment's status in state and persists to backend.
 * @param {number} id
 * @param {string} newStatus
 */
async function updateStatus(id, newStatus) {
  const validStatuses = ['pending', 'confirmed', 'completed', 'cancelled'];
  if (!validStatuses.includes(newStatus)) return;

  try {
    await apiUpdateStatus(id, newStatus);
    await loadAppointments();

    // If the drawer is open for this appointment, refresh it
    if (state.viewingId === id) openDrawer(id);
  } catch (err) {
    console.error('[PAWPOS] Status update error:', err);
    alert('Failed to update status. Please try again.');
  }
}

/**
 * Advances an appointment to the next logical status.
 * pending → confirmed → completed
 * @param {number} id
 */
async function advanceStatus(id) {
  const appt = appointments.find(a => a.id === id);
  if (!appt) return;
  const next = STATUS_NEXT[appt.status];
  if (next && next !== appt.status) await updateStatus(id, next);
}

/* Filtering & Sorting */

function getFiltered() {
  let list = [...appointments];

  if (state.searchQ) {
    const q = state.searchQ.toLowerCase();
    list = list.filter(a =>
      a.petName.toLowerCase().includes(q) ||
      a.owner.toLowerCase().includes(q)   ||
      a.groomer.toLowerCase().includes(q) ||
      a.service.toLowerCase().includes(q)
    );
  }

  if (state.statusF)  list = list.filter(a => a.status  === state.statusF);
  if (state.groomerF) list = list.filter(a => a.groomer === state.groomerF);

  list.sort((a, b) => (a.date + a.time).localeCompare(b.date + b.time));
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
  const todayAppts  = getTodayAppointments();
  const confirmed   = todayAppts.filter(a => a.status === 'confirmed').length;
  const pending     = appointments.filter(a => a.status === 'pending').length;
  const thisMonthC  = appointments.filter(a => a.status === 'confirmed').length;
  const thisMonthDone = appointments.filter(a => a.status === 'completed').length;

  setText('statToday',     todayAppts.length);
  document.getElementById('statTodayMeta') && (document.getElementById('statTodayMeta').innerHTML =
    `<span style="color:#0f6e56">${confirmed} confirmed</span> · ${todayAppts.length - confirmed} pending`);
  setText('statPending',   pending);
  setText('statConfirmed', thisMonthC);
  setText('statCompleted', thisMonthDone);

  const badge = document.getElementById('navBadge');
  if (badge) badge.textContent = todayAppts.filter(a => ['pending','confirmed'].includes(a.status)).length;

  setText('topbarSub', `${appointments.length} total · ${pending} pending · ${todayAppts.length} today`);
}

/* Render: Calendar */

/**
 * Renders the full monthly calendar with appointment events.
 */
function renderCalendar() {
  const labelEl = document.getElementById('calMonthLabel');
  if (labelEl) labelEl.textContent = `${MONTHS[state.calMonth]} ${state.calYear}`;

  // Day-of-week headers
  const dowEl = document.getElementById('calDOW');
  if (dowEl) dowEl.innerHTML = DAYS_SHORT.map(d => `<div class="cal-dow">${d}</div>`).join('');

  // Build day cells
  const firstDay   = new Date(state.calYear, state.calMonth, 1).getDay();
  const daysInMo   = new Date(state.calYear, state.calMonth + 1, 0).getDate();
  const daysInPrev = new Date(state.calYear, state.calMonth, 0).getDate();
  const totalCells = Math.ceil((firstDay + daysInMo) / 7) * 7;

  const bodyEl = document.getElementById('calBody');
  if (!bodyEl) return;

  let html = '';

  for (let i = 0; i < totalCells; i++) {
    let day, month, year, isOther = false;

    if (i < firstDay) {
      day = daysInPrev - firstDay + i + 1;
      month = state.calMonth - 1; year = state.calYear; isOther = true;
      if (month < 0) { month = 11; year--; }
    } else if (i >= firstDay + daysInMo) {
      day = i - firstDay - daysInMo + 1;
      month = state.calMonth + 1; year = state.calYear; isOther = true;
      if (month > 11) { month = 0; year++; }
    } else {
      day = i - firstDay + 1; month = state.calMonth; year = state.calYear;
    }

    const dateISO  = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
    const isToday  = dateISO === TODAY_ISO;
    const dayAppts = appointments.filter(a => a.date === dateISO && a.status !== 'cancelled');

    const MAX_VISIBLE = 2;
    const events = dayAppts.slice(0, MAX_VISIBLE).map(a =>
      `<div class="cal-event ${a.status}" data-id="${a.id}" title="${a.petName} · ${a.time} · ${STATUS_LABELS[a.status]}" role="button" tabindex="0" aria-label="${a.petName} at ${a.time}">${a.time} ${a.petName.split(' ')[0]}</div>`
    ).join('');

    const more = dayAppts.length > MAX_VISIBLE
      ? `<div class="cal-more" data-date="${dateISO}">+${dayAppts.length - MAX_VISIBLE} more</div>`
      : '';

    html += `
      <div class="cal-day ${isOther ? 'other-month' : ''} ${isToday ? 'today' : ''}" data-date="${dateISO}" role="button" tabindex="0" aria-label="${dateISO}">
        <div class="cal-day-num">${day}</div>
        ${events}${more}
      </div>
    `;
  }

  bodyEl.innerHTML = html;

  // Bind day cell clicks
  bodyEl.querySelectorAll('.cal-day').forEach(cell => {
    cell.addEventListener('click', e => {
      const eventEl = e.target.closest('.cal-event');
      const moreEl  = e.target.closest('.cal-more');

      if (eventEl) {
        openDrawer(Number(eventEl.dataset.id));
      } else if (moreEl) {
        openDayModal(moreEl.dataset.date);
      } else {
        openDayModal(cell.dataset.date);
      }
    });

    cell.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openDayModal(cell.dataset.date); }
    });
  });
}

/* Calendar Navigation */

document.getElementById('calPrev')?.addEventListener('click', () => {
  state.calMonth--;
  if (state.calMonth < 0) { state.calMonth = 11; state.calYear--; }
  renderCalendar();
});

document.getElementById('calNext')?.addEventListener('click', () => {
  state.calMonth++;
  if (state.calMonth > 11) { state.calMonth = 0; state.calYear++; }
  renderCalendar();
});

document.getElementById('calTodayBtn')?.addEventListener('click', () => {
  state.calYear  = new Date().getFullYear();
  state.calMonth = new Date().getMonth();
  renderCalendar();
});

/* Day Modal (Click A Calendar Day) */

/**
 * Opens the day appointments modal for a given date.
 * @param {string} dateISO
 */
function openDayModal(dateISO) {
  state.dayModalDate = dateISO;
  const dayAppts = getAppointmentsByDate(dateISO);
  const d = new Date(dateISO + 'T00:00:00');
  const label = d.toLocaleDateString('en-PH', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });

  setText('dayModalTitle', label);

  const body = document.getElementById('dayModalBody');
  if (body) {
    if (!dayAppts.length) {
      body.innerHTML = '<p style="text-align:center;color:#ccc;font-size:13px;padding:1rem 0">No appointments on this day.</p>';
    } else {
      body.innerHTML = dayAppts.map(a => `
        <div class="day-appt-item" data-id="${a.id}" role="button" tabindex="0" aria-label="${a.petName} at ${a.time}">
          <div class="day-appt-dot ${a.status}" aria-hidden="true"></div>
          <div style="font-size:18px">${PET_EMOJI[a.petId] || '🐾'}</div>
          <div class="day-appt-info">
            <div class="day-appt-name">${a.petName} — ${a.service}</div>
            <div class="day-appt-sub">${a.time} · ${a.groomer} · ${STATUS_LABELS[a.status]}</div>
          </div>
        </div>
      `).join('');

      body.querySelectorAll('.day-appt-item').forEach(item => {
        item.addEventListener('click',   () => { closeDayModal(); openDrawer(Number(item.dataset.id)); });
        item.addEventListener('keydown', e => { if (e.key === 'Enter') { closeDayModal(); openDrawer(Number(item.dataset.id)); } });
      });
    }
  }

  openModal('dayModal');
}

function closeDayModal() { closeModal('dayModal'); state.dayModalDate = null; }

document.getElementById('dayModalClose')?.addEventListener('click', closeDayModal);
document.getElementById('dayModal')?.addEventListener('click', e => { if (e.target === document.getElementById('dayModal')) closeDayModal(); });

document.getElementById('dayModalAddBtn')?.addEventListener('click', () => {
  const date = state.dayModalDate;
  closeDayModal();
  openAddModal(date);
});

/* Render: List View (Table) */

function renderList() {
  const { items } = getPage();
  const tbody = document.getElementById('apptTbody');
  const empty = document.getElementById('listEmpty');
  if (!tbody) return;

  if (!items.length) {
    tbody.innerHTML = '';
    if (empty) empty.style.display = '';
    return;
  }
  if (empty) empty.style.display = 'none';

  tbody.innerHTML = items.map(a => `
    <tr data-id="${a.id}" tabindex="0" role="button">
      <td>
        <div style="display:flex;align-items:center;gap:9px">
          <div style="width:30px;height:30px;border-radius:8px;background:${PET_BG[a.petId]||'#f5f5f5'};display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0">${PET_EMOJI[a.petId]||'🐾'}</div>
          <div>
            <div style="font-weight:500;color:#111">${a.petName.split('(')[0].trim()}</div>
            <div style="font-size:11px;color:#bbb">${(a.petName.match(/\(([^)]+)\)/) || [])[1] || ''}</div>
          </div>
        </div>
      </td>
      <td style="color:#888">${a.service}</td>
      <td style="color:#888">${a.date}</td>
      <td style="color:#888">${a.time}</td>
      <td style="color:#888">${a.groomer}</td>
      <td style="color:#888;font-size:12.5px">${a.owner}</td>
      <td><span class="badge ${a.status}">${STATUS_LABELS[a.status]}</span></td>
      <td>
        <div class="action-btns">
          <button class="act-btn edit-btn"   data-id="${a.id}" title="Edit"   aria-label="Edit appointment"><i class="ti ti-edit" aria-hidden="true"></i></button>
          <button class="act-btn delete-btn" data-id="${a.id}" title="Delete" aria-label="Delete appointment"><i class="ti ti-trash" aria-hidden="true"></i></button>
        </div>
      </td>
    </tr>
  `).join('');

  tbody.querySelectorAll('tr').forEach(row => {
    row.addEventListener('click', e => { if (!e.target.closest('.act-btn')) openDrawer(Number(row.dataset.id)); });
    row.addEventListener('keydown', e => { if ((e.key === 'Enter' || e.key === ' ') && !e.target.closest('.act-btn')) openDrawer(Number(row.dataset.id)); });
  });
  tbody.querySelectorAll('.edit-btn').forEach(b   => b.addEventListener('click', () => openEditModal(Number(b.dataset.id))));
  tbody.querySelectorAll('.delete-btn').forEach(b => b.addEventListener('click', () => openDeleteModal(Number(b.dataset.id))));
}

/* Render: Pagination */

function renderPagination() {
  const { total, totalPages } = getPage();
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
      html += `<button class="pg-btn ${i === state.currentPage ? 'active' : ''}" data-pg="${i}">${i}</button>`;
    else if (Math.abs(i - state.currentPage) === 2)
      html += `<button class="pg-btn" disabled style="border:none;background:none;color:#ccc">…</button>`;
  }
  html += `<button class="pg-btn" id="pgNext" ${state.currentPage === totalPages ? 'disabled' : ''} aria-label="Next page"><i class="ti ti-chevron-right" aria-hidden="true"></i></button>`;

  btns.innerHTML = html;
  btns.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { state.currentPage = Number(b.dataset.pg); renderList(); renderPagination(); }));
  document.getElementById('pgPrev')?.addEventListener('click', () => { if (state.currentPage > 1) { state.currentPage--; renderList(); renderPagination(); } });
  document.getElementById('pgNext')?.addEventListener('click', () => { if (state.currentPage < totalPages) { state.currentPage++; renderList(); renderPagination(); } });
}

/* Appointment Detail Drawer */

/**
 * Opens the slide-in detail drawer for an appointment.
 * @param {number} id
 */
function openDrawer(id) {
  const a = appointments.find(x => x.id === id);
  if (!a) return;
  state.viewingId = id;

  setText('drawerTitle', `${a.petName.split('(')[0].trim()} — ${a.service}`);

  document.getElementById('drawerBody').innerHTML = `
    <!-- Pet hero -->
    <div style="display:flex;align-items:center;gap:12px;padding:1rem 1.1rem;background:${PET_BG[a.petId]||'#f5f5f5'};border-radius:var(--radius-lg)">
      <div style="font-size:36px">${PET_EMOJI[a.petId] || '🐾'}</div>
      <div>
        <div style="font-family:var(--font-display);font-size:17px;font-weight:500;color:#111">${a.petName}</div>
        <div style="font-size:12.5px;color:#888;margin-top:2px">${a.service} · ${a.duration}</div>
      </div>
      <span class="badge ${a.status}" style="margin-left:auto">${STATUS_LABELS[a.status]}</span>
    </div>

    <!-- Status quick-change pills -->
    <div>
      <div class="section-title">Update status</div>
      <div class="drawer-status-row" id="statusPillsRow">
        ${['pending','confirmed','completed','cancelled'].map(s => `
          <button class="status-pill ${s} ${s === a.status ? 'active-status' : ''}" data-status="${s}" type="button" aria-pressed="${s === a.status}">
            ${STATUS_LABELS[s]}
          </button>
        `).join('')}
      </div>
    </div>

    <!-- Details -->
    <div>
      <div class="section-title">Appointment details</div>
      <div class="info-grid">
        <div class="info-item"><div class="info-item-label">Date</div><div class="info-item-value">${a.date}</div></div>
        <div class="info-item"><div class="info-item-label">Time</div><div class="info-item-value">${a.time}</div></div>
        <div class="info-item"><div class="info-item-label">Duration</div><div class="info-item-value">${a.duration}</div></div>
        <div class="info-item"><div class="info-item-label">Groomer</div><div class="info-item-value">${a.groomer}</div></div>
        <div class="info-item span2"><div class="info-item-label">Owner</div><div class="info-item-value">${a.owner}</div></div>
        ${a.notes ? `<div class="info-item span2"><div class="info-item-label">Notes</div><div class="info-item-value" style="font-weight:400;color:#555;white-space:normal">${a.notes}</div></div>` : ''}
      </div>
    </div>

    <!-- Advance status shortcut -->
    ${['pending','confirmed'].includes(a.status) ? `
      <button id="advanceStatusBtn" class="btn-primary" style="width:100%;justify-content:center" type="button">
        <i class="ti ti-arrow-right" aria-hidden="true"></i>
        Mark as ${STATUS_LABELS[STATUS_NEXT[a.status]]}
      </button>
    ` : ''}
  `;

  // Bind status pill clicks
  document.querySelectorAll('#statusPillsRow .status-pill').forEach(pill => {
    pill.addEventListener('click', () => updateStatus(id, pill.dataset.status));
  });

  // Bind advance status button
  document.getElementById('advanceStatusBtn')?.addEventListener('click', () => advanceStatus(id));

  document.getElementById('drawerOverlay').classList.add('open');
  document.getElementById('apptDrawer').classList.add('open');
}

function closeDrawer() {
  document.getElementById('drawerOverlay')?.classList.remove('open');
  document.getElementById('apptDrawer')?.classList.remove('open');
  state.viewingId = null;
}

document.getElementById('drawerClose')?.addEventListener('click', closeDrawer);
document.getElementById('drawerOverlay')?.addEventListener('click', closeDrawer);
document.getElementById('drawerEditBtn')?.addEventListener('click', () => { if (state.viewingId) { closeDrawer(); openEditModal(state.viewingId); } });
document.getElementById('drawerDeleteBtn')?.addEventListener('click', () => { if (state.viewingId) { closeDrawer(); openDeleteModal(state.viewingId); } });

/* Form: Add / Edit Appointment */

function readFormValues() {
  return {
    petId:    parseInt(document.getElementById('fPet')?.value)    || null,
    petName:  document.getElementById('fPet')?.options[document.getElementById('fPet')?.selectedIndex]?.text || '',
    owner:    document.getElementById('fOwner')?.value            || '',
    service:  document.getElementById('fService')?.value         || '',
    groomer:  document.getElementById('fGroomer')?.value         || '',
    date:     document.getElementById('fDate')?.value            || '',
    time:     document.getElementById('fTime')?.value            || '',
    duration: document.getElementById('fDuration')?.value        || '1 hour',
    status:   document.getElementById('fStatus')?.value         || 'pending',
    notes:    document.getElementById('fNotes')?.value.trim()    || ''
  };
}

/**
 * Validates the appointment form.
 * @param {Object} values
 * @returns {{ valid: boolean, message: string }}
 */
function validateApptForm(values) {
  if (!values.petId)   return { valid: false, message: 'Please select a pet.' };
  if (!values.service) return { valid: false, message: 'Please select a service.' };
  if (!values.groomer) return { valid: false, message: 'Please select a groomer.' };
  if (!values.date)    return { valid: false, message: 'Please select a date.' };
  if (!values.time)    return { valid: false, message: 'Please select a time.' };

  // Conflict check
  const { conflict, message } = checkScheduleConflict(
    values.groomer, values.date, values.time, values.duration, state.editingId
  );
  if (conflict) return { valid: false, message };

  return { valid: true, message: '' };
}

function clearForm() {
  ['fPet','fOwner','fService','fGroomer','fStatus'].forEach(id => { const el = document.getElementById(id); if (el) el.value = ''; });
  const fDate = document.getElementById('fDate'); if (fDate) fDate.value = TODAY_ISO;
  const fTime = document.getElementById('fTime'); if (fTime) fTime.value = '09:00';
  const fDur  = document.getElementById('fDuration'); if (fDur) fDur.value = '1 hour';
  const fStat = document.getElementById('fStatus');   if (fStat) fStat.value = 'pending';
  const fNote = document.getElementById('fNotes');    if (fNote) fNote.value = '';
}

function populateForm(a) {
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val ?? ''; };
  set('fPet',      a.petId);
  syncAppointmentOwner();
  set('fService',  a.service);
  set('fGroomer',  a.groomer);
  set('fDate',     a.date);
  set('fTime',     a.time);
  set('fDuration', a.duration);
  set('fStatus',   a.status);
  set('fNotes',    a.notes);
}

/**
 * Opens the modal in Add mode, optionally pre-filling the date.
 * @param {string|null} prefillDate
 */
function openAddModal(prefillDate = null) {
  state.editingId = null;
  clearForm();
  if (prefillDate) { const d = document.getElementById('fDate'); if (d) d.value = prefillDate; }
  setText('apptModalTitle', 'Book appointment');
  const saveBtn = document.getElementById('apptModalSave');
  if (saveBtn) saveBtn.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save appointment';
  openModal('apptModal');
  document.getElementById('fPet')?.focus();
}

function openEditModal(id) {
  const a = appointments.find(x => x.id === id);
  if (!a) return;
  state.editingId = id;
  populateForm(a);
  setText('apptModalTitle', 'Edit appointment');
  const saveBtn = document.getElementById('apptModalSave');
  if (saveBtn) saveBtn.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Update appointment';
  openModal('apptModal');
  document.getElementById('fPet')?.focus();
}

async function handleSave() {
  const values = readFormValues();
  const { valid, message } = validateApptForm(values);
  if (!valid) { alert(message); return; }

  const saveBtn = document.getElementById('apptModalSave');
  if (saveBtn) { saveBtn.disabled = true; saveBtn.innerHTML = '<i class="ti ti-loader spin" aria-hidden="true"></i> Saving...'; }

  try {
    if (state.editingId) {
      await apiUpdateAppointment(state.editingId, values);
    } else {
      await apiCreateAppointment(values);
    }
    closeModal('apptModal');
    await loadAppointments();
  } catch (err) {
    console.error('[PAWPOS] Save appointment error:', err);
    alert('Failed to save appointment. Please try again.');
  } finally {
    if (saveBtn) { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="ti ti-device-floppy" aria-hidden="true"></i> Save appointment'; }
  }
}

document.getElementById('addApptBtn')?.addEventListener('click',   () => openAddModal(null));
document.getElementById('fPet')?.addEventListener('change', syncAppointmentOwner);
document.getElementById('apptModalSave')?.addEventListener('click', handleSave);
document.getElementById('apptModalClose')?.addEventListener('click',  () => closeModal('apptModal'));
document.getElementById('apptModalCancel')?.addEventListener('click', () => closeModal('apptModal'));
document.getElementById('apptModal')?.addEventListener('click', e => { if (e.target === document.getElementById('apptModal')) closeModal('apptModal'); });

/* Delete */

function openDeleteModal(id) {
  const a = appointments.find(x => x.id === id);
  if (!a) return;
  state.deletingId = id;
  const msgEl = document.getElementById('deleteMsg');
  if (msgEl) msgEl.textContent = `"${a.petName} — ${a.service}" on ${a.date} will be permanently removed.`;
  openModal('deleteModal');
}

async function handleDelete() {
  if (!state.deletingId) return;
  const btn = document.getElementById('deleteConfirmBtn');
  if (btn) { btn.disabled = true; btn.textContent = 'Deleting...'; }
  try {
    await apiDeleteAppointment(state.deletingId);
    closeModal('deleteModal');
    await loadAppointments();
  } catch (err) {
    console.error('[PAWPOS] Delete appointment error:', err);
    alert('Failed to delete appointment. Please try again.');
  } finally {
    if (btn) { btn.disabled = false; btn.textContent = 'Delete'; }
    state.deletingId = null;
  }
}

document.getElementById('deleteConfirmBtn')?.addEventListener('click', handleDelete);
document.getElementById('deleteCancelBtn')?.addEventListener('click',  () => { state.deletingId = null; closeModal('deleteModal'); });
document.getElementById('deleteModal')?.addEventListener('click', e => { if (e.target === document.getElementById('deleteModal')) { state.deletingId = null; closeModal('deleteModal'); } });

/* Tabs */

document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => { b.classList.remove('active'); b.setAttribute('aria-selected', 'false'); });
    btn.classList.add('active');
    btn.setAttribute('aria-selected', 'true');
    state.activeTab = btn.dataset.tab;
    state.currentPage = 1;

    const calView  = document.getElementById('calendarView');
    const listView = document.getElementById('listView');
    const toolbar  = document.getElementById('listToolbar');

    if (calView)  calView.style.display  = state.activeTab === 'calendar' ? '' : 'none';
    if (listView) listView.style.display = state.activeTab === 'list'     ? '' : 'none';
    if (toolbar)  toolbar.style.display  = state.activeTab === 'list'     ? '' : 'none';

    if (state.activeTab === 'calendar') renderCalendar();
    else { renderList(); renderPagination(); }
  });
});

/* Search & Filter Controls */

const searchInput = document.getElementById('searchInput');
if (searchInput) {
  let searchTimer;
  searchInput.addEventListener('input', e => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { state.searchQ = e.target.value.trim(); state.currentPage = 1; renderList(); renderPagination(); }, 250);
  });
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Escape') { searchInput.value = ''; state.searchQ = ''; state.currentPage = 1; renderList(); renderPagination(); }
  });
}

document.getElementById('statusFilter')?.addEventListener('change', e => { state.statusF = e.target.value; state.currentPage = 1; renderList(); renderPagination(); });
document.getElementById('groomerFilter')?.addEventListener('change', e => { state.groomerF = e.target.value; state.currentPage = 1; renderList(); renderPagination(); });

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
  if (e.key === 'Escape') { closeModal('apptModal'); closeModal('deleteModal'); closeModal('dayModal'); closeDrawer(); }
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
  if (document.getElementById('pawpos-appt-spin')) return;
  const style = document.createElement('style');
  style.id = 'pawpos-appt-spin';
  style.textContent = '@keyframes spin { to { transform: rotate(360deg); } } .spin { display: inline-block; animation: spin 0.8s linear infinite; }';
  document.head.appendChild(style);
})();

/* Public Exports */
window.PAWAppointments = {
  getAll:        () => [...appointments],
  getByDate:     getAppointmentsByDate,
  getToday:      getTodayAppointments,
  updateStatus,
  advanceStatus
};

/* Init */
loadAppointments().catch(err => {
  console.error('[PAWPOS] Load appointments error:', err);
  alert(err.message || 'Unable to load appointments.');
});
