const eventsBody = document.getElementById('eventsBody');
const searchInput = document.getElementById('searchInput');
const statusText = document.getElementById('statusText');

let allEvents = [];
let detailContainer = null;

const SPORT_LABELS = {
  1: 'OB',
  2: 'LOB',
  3: 'MTBO',
  4: 'TRAIL',
};

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function formatSport(ev) {
  if (ev.sport_name && String(ev.sport_name).trim() !== '') {
    const raw = String(ev.sport_name).trim();
    return SPORT_LABELS[raw] ?? raw;
  }
  if (ev.sport_id && String(ev.sport_id).trim() !== '') {
    const idStr = String(ev.sport_id).trim();
    return SPORT_LABELS[idStr] ?? idStr;
  }
  return '—';
}

function formatGenderLabel(gender) {
  if (gender === 'M') return 'M';
  if (gender === 'W' || gender === 'F') return 'W';
  return '—';
}

function isRankingEvent(ev) {
  const levelCode = String(ev.level_code ?? '').trim().toUpperCase();
  const levelName = String(ev.level_name ?? '').trim().toLowerCase();
  const discName = String(ev.discipline_name ?? '').trim().toLowerCase();
  const name = String(ev.name ?? '').trim().toLowerCase();

  if (
    levelCode === 'SEM' ||
    levelName.includes('seminář') ||
    levelName.includes('školení') ||
    levelName.includes('workshop')
  ) {
    return false;
  }
  if (
    discName === 'sem' ||
    name.startsWith('metodický seminář') ||
    name.startsWith('školení ') ||
    name.startsWith('workshop ')
  ) {
    return false;
  }

  return true;
}

function ensureDetailContainer() {
  if (detailContainer) return detailContainer;

  const mainEl = document.querySelector('main.container') || document.body;
  detailContainer = document.createElement('section');
  detailContainer.id = 'eventDetailPanel';
  detailContainer.style.marginTop = '1.5rem';
  mainEl.appendChild(detailContainer);
  return detailContainer;
}

function renderClassTable(cls) {
  const entries = Array.isArray(cls.entries) ? cls.entries : [];
  const totalCount = entries.length;
  const genderLabel = formatGenderLabel(cls.gender);
  const koefLabel =
    cls.ranking_koef !== undefined && cls.ranking_koef !== null
      ? `koef. ${escapeHtml(cls.ranking_koef)}`
      : '';
  const metaParts = [genderLabel, koefLabel, `${totalCount} závodníků`].filter(Boolean);

  const missingSnapshotBadge = !cls.has_snapshot
    ? `<span style="background:#ffeef0;color:#cf222e;padding:0.2rem 0.55rem;border-radius:999px;font-size:0.8rem;font-weight:600;">Ranking pro toto datum/pohlaví není k dispozici</span>`
    : '';

  const rowsHtml = totalCount
    ? entries
        .map((entry, index) => {
          const seqNo = index + 1;
          const rowClass = index < 4 ? ' class="top4-row"' : '';

          return `
            <tr${rowClass}>
              <td class="col-idx nowrap">${seqNo}.</td>
              <td class="nowrap">${escapeHtml(entry.rank_position ?? '—')}</td>
              <td class="nowrap">${escapeHtml(entry.reg_no || '—')}</td>
              <td>${escapeHtml(entry.name || '—')}</td>
              <td class="nowrap">${escapeHtml(entry.points ?? '—')}</td>
              <td class="nowrap">${escapeHtml(entry.ranking_coef ?? '—')}</td>
            </tr>
          `;
        })
        .join('')
    : `
      <tr>
        <td colspan="6" class="empty-cell">Žádní přihlášení závodníci v této kategorii.</td>
      </tr>
    `;

  return `
    <div class="class-block" style="margin-top:1.25rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.5rem;">
        <h3 style="margin:0;font-size:1.05rem;">
          ${escapeHtml(cls.name || '')}
          <span style="color:var(--muted);font-weight:400;font-size:0.9rem;">(${metaParts.join(' · ')})</span>
        </h3>
        ${missingSnapshotBadge}
      </div>
      <div class="table-wrap">
        <table class="events-table">
          <thead>
            <tr>
              <th class="col-idx nowrap">#</th>
              <th class="nowrap">RANK. POŘADÍ</th>
              <th class="nowrap">REG. Č.</th>
              <th>JMÉNO</th>
              <th class="nowrap">BODY</th>
              <th class="nowrap">KOEF.</th>
            </tr>
          </thead>
          <tbody>
            ${rowsHtml}
          </tbody>
        </table>
      </div>
    </div>
  `;
}

function renderEventDetail(data) {
  const panel = ensureDetailContainer();
  const ev = data.event || {};
  const classes = Array.isArray(data.classes) ? data.classes : [];

  const classButtonsHtml = classes
    .map(
      (cls, idx) =>
        `<button type="button" class="class-jump-btn" data-class-idx="${idx}">${escapeHtml(cls.name)}</button>`
    )
    .join('');

  const classesHtml = classes.length
    ? classes.map((cls) => renderClassTable(cls)).join('')
    : '<p style="color:var(--muted);margin:1rem 0 0;">Závod nemá žádné kategorie se započítávaným rankingem.</p>';

  panel.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-top:4px solid var(--accent);border-radius:8px;padding:1.25rem;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <h2 style="margin:0;font-size:1.3rem;">
          ${escapeHtml(ev.name || 'Detail závodu')} (#${escapeHtml(ev.oris_id || '')})
        </h2>
        <button id="closeDetailBtn" type="button" class="detail-btn">
          Zavřít
        </button>
      </div>

      ${
        classes.length
          ? `<div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:1rem;padding-top:0.85rem;border-top:1px solid var(--border);">${classButtonsHtml}</div>`
          : ''
      }

      <div id="classesList">${classesHtml}</div>
    </div>
  `;

  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  document.getElementById('closeDetailBtn')?.addEventListener('click', () => {
    panel.innerHTML = '';
  });

  panel.querySelectorAll('.class-jump-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      const idx = Number(btn.getAttribute('data-class-idx'));
      const blocks = panel.querySelectorAll('.class-block');
      blocks[idx]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
  });
}

async function openEventDetail(eventId) {
  const id = Number(eventId);
  if (!id) return;

  const panel = ensureDetailContainer();
  panel.innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1.25rem;color:var(--muted);">
      Načítám ranking pro závod #${escapeHtml(id)}...
    </div>
  `;
  panel.scrollIntoView({ behavior: 'smooth', block: 'start' });

  try {
    const response = await fetch(`api/event_ranking.php?event_id=${encodeURIComponent(id)}`);
    const data = await response.json();
    if (!response.ok || data.error) {
      throw new Error(data.error || `HTTP ${response.status}`);
    }
    renderEventDetail(data);
  } catch (err) {
    panel.innerHTML = `
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:1.25rem;color:#cf222e;">
        Chyba při načítání detailu rankingu: ${escapeHtml(err.message)}
      </div>
    `;
  }
}

function renderEvents(events) {
  if (!eventsBody) return;

  if (!events.length) {
    eventsBody.innerHTML =
      '<tr><td colspan="9" class="empty-cell">Žádné závody neodpovídají filtru.</td></tr>';
    return;
  }

  eventsBody.innerHTML = events
    .map((ev) => {
      const id = escapeHtml(ev.oris_id);
      const orisUrl = `https://oris.ceskyorientak.cz/Zavod?id=${encodeURIComponent(ev.oris_id)}`;
      const cancelledClass = Number(ev.is_cancelled) === 1 ? ' class="cancelled-row"' : '';
      const sportLabel = formatSport(ev);

      return `
      <tr data-oris-id="${id}"${cancelledClass} style="cursor:pointer;">
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="nowrap">
          <a class="oris-link" href="${orisUrl}" target="_blank" rel="noopener noreferrer">#${id}</a>
        </td>
        <td>
          <div class="event-name-cell">
            <strong>${escapeHtml(ev.name)}</strong>
            <button type="button" class="detail-btn" data-event-id="${id}">Detail →</button>
          </div>
        </td>
        <td class="nowrap">${escapeHtml(ev.clubs || '—')}</td>
        <td class="nowrap">${escapeHtml(ev.region || '—')}</td>
        <td class="nowrap">${escapeHtml(sportLabel)}</td>
        <td class="nowrap">${escapeHtml(ev.discipline_name || '—')}</td>
        <td class="nowrap">${escapeHtml(ev.level_code || ev.level_name || '—')}</td>
        <td class="nowrap">${escapeHtml(ev.entries_count ?? 0)}</td>
      </tr>
    `;
    })
    .join('');
}

eventsBody?.addEventListener('click', (event) => {
  const target = event.target;
  if (!(target instanceof Element)) return;

  if (target.closest('a.oris-link')) {
    return;
  }

  const trigger = target.closest('[data-event-id]');
  if (trigger) {
    openEventDetail(trigger.getAttribute('data-event-id'));
    return;
  }

  const row = target.closest('tr[data-oris-id]');
  if (row) {
    openEventDetail(row.getAttribute('data-oris-id'));
  }
});

function applyFilter() {
  const baseEvents = allEvents.filter(isRankingEvent);
  const query = (searchInput?.value ?? '').trim().toLowerCase();

  if (!query) {
    renderEvents(baseEvents);
    return;
  }

  const filtered = baseEvents.filter((ev) => {
    const haystack = [
      ev.name,
      ev.clubs,
      ev.region,
      ev.sport_name,
      ev.sport_id,
      formatSport(ev),
      ev.discipline_name,
      ev.level_code,
      ev.level_name,
      ev.oris_id,
    ]
      .join(' ')
      .toLowerCase();
    return haystack.includes(query);
  });

  renderEvents(filtered);
}

async function loadEvents() {
  if (statusText) {
    statusText.textContent = 'Načítám závody...';
  }

  try {
    const response = await fetch('api/events.php');
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();
    if (data.error) {
      throw new Error(data.error);
    }

    allEvents = Array.isArray(data.events) ? data.events : [];
    const rankingCount = allEvents.filter(isRankingEvent).length;
    if (statusText) {
      statusText.textContent = `Zobrazeno ${rankingCount} rankingových závodů (${data.dateFrom} až ${data.dateTo})`;
    }
    applyFilter();
  } catch (err) {
    if (statusText) {
      statusText.textContent = 'Chyba při načítání';
    }
    if (eventsBody) {
      eventsBody.innerHTML = `<tr><td colspan="9" class="empty-cell">Nepodařilo se načíst závody: ${escapeHtml(err.message)}</td></tr>`;
    }
  }
}

searchInput?.addEventListener('input', applyFilter);

loadEvents();
