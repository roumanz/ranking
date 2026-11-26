const eventsBody = document.getElementById('eventsBody');
const searchInput = document.getElementById('searchInput');
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
  if (!eventsBody) return;

  if (!events.length) {
    eventsBody.innerHTML = '<tr><td colspan="5" class="empty-cell">Žádné závody neodpovídají filtru.</td></tr>';
    return;
  }

  eventsBody.innerHTML = events
    .map((ev) => {
      const id = escapeHtml(ev.oris_id);
      const orisUrl = `https://oris.ceskyorientak.cz/akce/${encodeURIComponent(ev.oris_id)}`;

      return `
      <tr data-oris-id="${id}">
        <td class="nowrap">${escapeHtml(ev.event_date)}</td>
        <td class="nowrap"><a class="oris-link" href="${orisUrl}" target="_blank" rel="noopener noreferrer">#${id}</a></td>
        <td><strong>${escapeHtml(ev.name)}</strong></td>
        <td>${escapeHtml(ev.clubs || '—')}</td>
        <td>${escapeHtml(ev.region || '—')}</td>
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
    const haystack = `${ev.name} ${ev.clubs} ${ev.region} ${ev.oris_id}`.toLowerCase();
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
      eventsBody.innerHTML = `<tr><td colspan="5" class="empty-cell">Nepodařilo se načíst závody: ${escapeHtml(err.message)}</td></tr>`;
    }
  }
}

searchInput?.addEventListener('input', applyFilter);

loadEvents();
