const searchInput = document.getElementById('searchInput');
const statusText = document.getElementById('statusText');

searchInput?.addEventListener('input', () => {
  if (statusText) {
    statusText.textContent = searchInput.value.trim()
      ? `Hledám: ${searchInput.value.trim()}`
      : 'Krok 1 – statická kostra';
  }
});

console.log('ORIS Ranking Krok 1 načten');
