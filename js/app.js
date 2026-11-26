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
let activeEventId = null;

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
    return '<span class="muted-text">—</span>';
  }
  const typeLabel = ev.ranking_type === 'sprint' ? 'Sprint' : 'Les';
  const datePart = ev.ranking_decision_date ? ` · ${ev.ranking_decision_date}` : '';
  return `<span class="ranking-pill">${escapeHtml(typeLabel + datePart)}</span>`;
}

function highlightActiveRow() {
  if (!eventsBody) return;
  eventsBody.querySelectorAll('tr.event-row').forEach((tr) => {
    const isSelected = String(tr.getAttribute('data-oris-id')) === String(activeEventId ?? '');
    tr.classList.toggle('is-selected', isSelected);
  });
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
      const selectedClass = String(ev.oris_id) === String(activeEventId ?? '') ? ' is-selected' : '';
      const sportLabel = formatSport(ev);
      const rankingBadge = formatRankingBadge(ev);

      return `
      <tr class="event-row${cancelledClass}${selectedClass}" data-oris-id="${id}">
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="nowrap"><a class="oris-link" href="${orisUrl}" target="_blank" rel="noopener noreferrer">#${id}</a></td>
        <td>
          <button type="button" class="event-trigger" data-event-id="${id}" title="Zobrazit kategorie a ranking">
            <span class="event-name-text">${escapeHtml(ev.name)}</span>
            <span class="event-action-hint">Detail →</span>
          </button>
        </td>
        <td class="nowrap">${escapeHtml(ev.clubs || '—')}</td>
        <td class="nowrap">${escapeHtml(ev.region || '—')}</td>
        <td class="nowrap">${escapeHtml(sportLabel)}</td>
        <td class="nowrap">${escapeHtml(ev.discipline_name || '—')}</td>
        <td class="nowrap">${rankingBadge}</td>
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

function buildImportLink(rankingType, gender, decisionDate) {
  const rankCode = rankingType === 'sprint' ? '8' : '2';
  const params = new URLSearchParams({
    ranking_type: rankingType || 'forest',
    gender: gender || 'M',
    date: decisionDate || '2026-11-30',
    ranktype: rankCode,
  });
  return `api/import_ranking.php?${params.toString()}`;
}

function renderEventRankingDetail(payload) {
  if (!detailSection || !detailTitle || !detailMeta || !detailClasses) return;

  const ev = payload.event || {};
  const rankType = ev.ranking_type || 'forest';
  const decDate = ev.decision_date || '—';
  const snaps = Array.isArray(payload.snapshots_found) ? payload.snapshots_found : [];

  detailTitle.textContent = `${ev.name || 'Závod'} (#${ev.oris_id || ''})`;
  detailMeta.innerHTML = `
    <span><strong>Typ:</strong> ${escapeHtml(rankType === 'sprint' ? 'Sprint' : 'Les')}</span>
    <span><strong>Rozhodné datum:</strong> ${escapeHtml(decDate)}</span>
    <span><strong>Snapshoty v DB:</strong> ${snaps.length ? escapeHtml(snaps.join(', ')) : 'žádný'}</span>
  `;

  const classes = Array.isArray(payload.classes) ? payload.classes : [];
  if (!classes.length) {
    detailClasses.innerHTML = '<p class="empty-cell">Tento závod nemá žádné rankingové kategorie.</p>';
    detailSection.hidden = false;
    return;
  }

  detailClasses.innerHTML = classes
    .map((cls) => {
      const importHref = buildImportLink(rankType, cls.gender, ev.decision_date);
      const snapStatusHtml = cls.has_snapshot
        ? `<span class="snap-ok">Snapshot #${escapeHtml(cls.snapshot_id)}</span>`
        : `<span class="snap-missing">Chybí snapshot v DB — <a href="${escapeHtml(importHref)}" target="_blank" rel="noopener">importovat (${escapeHtml(rankType)} / ${escapeHtml(cls.gender || '?')})</a></span>`;

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
          <div class="class-header">
            <h3>${escapeHtml(cls.name)} <span class="class-sub">(${escapeHtml(cls.gender || '?')}, koef. ${escapeHtml(cls.ranking_koef)})</span></h3>
            <div>${snapStatusHtml}</div>
          </div>
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

  activeEventId = String(eventId);
  highlightActiveRow();

  detailSection.hidden = false;
  detailTitle.textContent = `Načítám závod #${eventId}...`;
  detailMeta.innerHTML = '';
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
    detailClasses.innerHTML = `<p class="snap-missing">${escapeHtml(err.message)}</p>`;
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
  activeEventId = null;
  highlightActiveRow();
  if (detailSection) {
    detailSection.hidden = true;
  }
});

searchInput?.addEventListener('input', applyFilter);
onlyRankingInput?.addEventListener('change', loadEvents);
rankingTypeSelect?.addEventListener('change', loadEvents);

loadEvents();
