const eventsBody = document.getElementById('eventsBody');
const searchInput = document.getElementById('searchInput');
const onlyRankingInput = document.getElementById('onlyRankingInput');
const rankingTypeSelect = document.getElementById('rankingTypeSelect');
const statusText = document.getElementById('statusText');

const detailSection = document.getElementById('eventDetailSection');
const detailTitle = document.getElementById('detailTitle');
const detailMeta = document.getElementById('detailMeta');
const detailClasses = document.getElementById('detailClasses');
const closeDetailBtn = document.getElementById('closeDetailBtn');

let allEvents = [];

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

function formatRankingBadge(ev) {
  if (Number(ev.has_ranking) !== 1) {
    return '—';
  }
  const typeLabel = ev.ranking_type === 'sprint' ? 'Sprint' : 'Les';
  const datePart = ev.ranking_decision_date ? ` (${ev.ranking_decision_date})` : '';
  return `${typeLabel}${datePart}`;
}

function renderEvents(events) {
  if (!eventsBody) return;

  if (!events.length) {
    eventsBody.innerHTML = '<tr><td colspan="9" class="empty-cell">Žádné závody neodpovídají filtru.</td></tr>';
    return;
  }

  eventsBody.innerHTML = events
    .map((ev) => {
      const id = escapeHtml(ev.oris_id);
      const orisUrl = `https://oris.ceskyorientak.cz/Zavod?id=${encodeURIComponent(ev.oris_id)}`;
      const cancelledClass = Number(ev.is_cancelled) === 1 ? ' cancelled-row' : '';
      const sportLabel = formatSport(ev);
      const rankingLabel = formatRankingBadge(ev);

      return `
      <tr class="event-row${cancelledClass}" data-oris-id="${id}" style="cursor:pointer">
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="nowrap"><a class="oris-link" href="${orisUrl}" target="_blank" rel="noopener noreferrer">#${id}</a></td>
        <td><strong>${escapeHtml(ev.name)}</strong></td>
        <td class="nowrap">${escapeHtml(ev.clubs || '—')}</td>
        <td class="nowrap">${escapeHtml(ev.region || '—')}</td>
        <td class="nowrap">${escapeHtml(sportLabel)}</td>
        <td class="nowrap">${escapeHtml(ev.discipline_name || '—')}</td>
        <td class="nowrap">${escapeHtml(rankingLabel)}</td>
        <td class="nowrap">${escapeHtml(ev.entries_count ?? 0)}</td>
      </tr>
    `;
    })
    .join('');
}

function applyFilter() {
  const query = (searchInput?.value ?? '').trim().toLowerCase();
  if (!query) {
    renderEvents(allEvents);
    return;
  }

  const filtered = allEvents.filter((ev) => {
    const haystack = [
      ev.name,
      ev.clubs,
      ev.region,
      ev.sport_name,
      ev.sport_id,
      formatSport(ev),
      ev.discipline_name,
      ev.ranking_type,
      ev.ranking_decision_date,
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

  const params = new URLSearchParams();
  if (onlyRankingInput?.checked) {
    params.set('has_ranking', '1');
  }
  const selectedType = rankingTypeSelect?.value ?? '';
  if (selectedType) {
    params.set('ranking_type', selectedType);
  }

  const url = `api/events.php${params.toString() ? `?${params.toString()}` : ''}`;

  try {
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();
    if (data.error) {
      throw new Error(data.error);
    }

    allEvents = Array.isArray(data.events) ? data.events : [];
    if (statusText) {
      statusText.textContent = `Zobrazeno ${allEvents.length} závodů (${data.dateFrom} až ${data.dateTo})`;
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

function renderEventRankingDetail(payload) {
  if (!detailSection || !detailTitle || !detailMeta || !detailClasses) return;

  const ev = payload.event || {};
  detailTitle.textContent = `${ev.name || 'Závod'} (#${ev.oris_id || ''})`;
  detailMeta.textContent = `Typ rankingu: ${ev.ranking_type || '—'} | Rozhodné datum: ${ev.decision_date || '—'} | Snapshoty v DB: ${(payload.snapshots_found || []).join(', ') || 'žádný'}`;

  const classes = Array.isArray(payload.classes) ? payload.classes : [];
  if (!classes.length) {
    detailClasses.innerHTML = '<p>Tento závod nemá žádné rankingové kategorie.</p>';
    detailSection.hidden = false;
    return;
  }

  detailClasses.innerHTML = classes
    .map((cls) => {
      const snapNote = cls.has_snapshot
        ? `Snapshot #${cls.snapshot_id}`
        : 'Chybí snapshot v DB (naimportuj přes import_ranking.php)';

      const rowsHtml = (cls.entries || [])
        .map((ent) => `
          <tr>
            <td class="nowrap">${ent.rank_position ?? '—'}</td>
            <td class="nowrap">${escapeHtml(ent.reg_no || '—')}</td>
            <td>${escapeHtml(ent.name || '—')}</td>
            <td class="nowrap">${escapeHtml(ent.club || '—')}</td>
            <td class="nowrap">${ent.points ?? '—'}</td>
            <td class="nowrap">${ent.ranking_coef ?? '—'}</td>
          </tr>
        `)
        .join('');

      return `
        <div class="class-block">
          <h3>${escapeHtml(cls.name)} (Pohlaví: ${escapeHtml(cls.gender || '?')}, koef.: ${escapeHtml(cls.ranking_koef)}) — ${escapeHtml(snapNote)}</h3>
          <table class="events-table">
            <thead>
              <tr>
                <th>Rank. pořadí</th>
                <th>Reg. č.</th>
                <th>Jméno</th>
                <th>Klub</th>
                <th>Body</th>
                <th>Koef.</th>
              </tr>
            </thead>
            <tbody>
              ${rowsHtml || '<tr><td colspan="6" class="empty-cell">Žádné přihlášky v kategorii.</td></tr>'}
            </tbody>
          </table>
        </div>
      `;
    })
    .join('');

  detailSection.hidden = false;
  detailSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

async function openEventDetail(eventId) {
  if (!detailSection || !detailTitle || !detailMeta || !detailClasses) return;

  detailSection.hidden = false;
  detailTitle.textContent = `Načítám závod #${eventId}...`;
  detailMeta.textContent = '';
  detailClasses.innerHTML = '';

  try {
    const resp = await fetch(`api/event_ranking.php?event_id=${encodeURIComponent(eventId)}`);
    const data = await resp.json();
    if (!resp.ok || data.error) {
      throw new Error(data.error || `HTTP ${resp.status}`);
    }
    renderEventRankingDetail(data);
  } catch (err) {
    detailTitle.textContent = `Chyba u závodu #${eventId}`;
    detailClasses.innerHTML = `<p>${escapeHtml(err.message)}</p>`;
  }
}

eventsBody?.addEventListener('click', (e) => {
  if (e.target instanceof Element && e.target.closest('a')) {
    return;
  }
  const row = e.target instanceof Element ? e.target.closest('tr.event-row') : null;
  const eventId = row?.getAttribute('data-oris-id');
  if (eventId) {
    openEventDetail(eventId);
  }
});

closeDetailBtn?.addEventListener('click', () => {
  if (detailSection) {
    detailSection.hidden = true;
  }
});

searchInput?.addEventListener('input', applyFilter);
onlyRankingInput?.addEventListener('change', loadEvents);
rankingTypeSelect?.addEventListener('change', loadEvents);

loadEvents();
