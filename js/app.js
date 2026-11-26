const eventsBody = document.getElementById('eventsBody');
const searchInput = document.getElementById('searchInput');
const refreshBtn = document.getElementById('refreshBtn');
const statusText = document.getElementById('statusText');

let allEvents = [];

function escapeHtml(value) {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;');
}

function renderEvents(events) {
  if (!events.length) {
    eventsBody.innerHTML = '<tr><td colspan="5" class="empty-cell">Žádné závody neodpovídají filtru.</td></tr>';
    return;
  }

  eventsBody.innerHTML = events
    .map(
      (ev) => `
      <tr data-oris-id="${escapeHtml(ev.oris_id)}">
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="muted">${escapeHtml(ev.oris_id)}</td>
        <td><strong>${escapeHtml(ev.name)}</strong></td>
        <td>${escapeHtml(ev.clubs || '—')}</td>
        <td>${escapeHtml(ev.region || '—')}</td>
      </tr>
    `
    )
    .join('');
}

function applyFilter() {
  const query = searchInput.value.trim().toLowerCase();
  if (!query) {
    renderEvents(allEvents);
    return;
  }

  const filtered = allEvents.filter((ev) => {
    const haystack = `${ev.name} ${ev.clubs} ${ev.region} ${ev.oris_id}`.toLowerCase();
    return haystack.includes(query);
  });

  renderEvents(filtered);
}

async function loadEvents(forceRefresh = false) {
  refreshBtn.disabled = true;
  statusText.textContent = forceRefresh ? 'Synchronizuji s ORIS API...' : 'Načítám z databáze...';

  try {
    const url = forceRefresh ? 'api/events.php?refresh=1' : 'api/events.php';
    const response = await fetch(url);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const data = await response.json();
    if (data.error) {
      throw new Error(data.error);
    }

    allEvents = Array.isArray(data.events) ? data.events : [];
    statusText.textContent = `Zobrazeno ${allEvents.length} závodů (${data.dateFrom} až ${data.dateTo})`;
    applyFilter();
  } catch (err) {
    statusText.textContent = 'Chyba při načítání';
    eventsBody.innerHTML = `<tr><td colspan="5" class="empty-cell">Nepodařilo se načíst závody: ${escapeHtml(err.message)}</td></tr>`;
  } finally {
    refreshBtn.disabled = false;
  }
}

searchInput.addEventListener('input', applyFilter);
refreshBtn.addEventListener('click', () => loadEvents(true));

loadEvents(false);
