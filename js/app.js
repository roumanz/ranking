const eventsBody = document.getElementById('eventsBody');
const searchInput = document.getElementById('searchInput');
const statusText = document.getElementById('statusText');

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
      const cancelledClass = Number(ev.is_cancelled) === 1 ? ' class="cancelled-row"' : '';
      const sportLabel = formatSport(ev);

      return `
      <tr data-oris-id="${id}"${cancelledClass}>
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="nowrap"><a class="oris-link" href="${orisUrl}" target="_blank" rel="noopener noreferrer">#${id}</a></td>
        <td><strong>${escapeHtml(ev.name)}</strong></td>
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

searchInput?.addEventListener('input', applyFilter);

loadEvents();
