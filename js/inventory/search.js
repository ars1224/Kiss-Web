// Split from original designScript.js

// ===== Search Bar Clear Button (X) =====
(function () {
  const input  = document.getElementById('searchEntry');
  const clearX = document.getElementById('searchClearX');
  if (!input || !clearX) return;

  function toggleClear() {
    clearX.classList.toggle('d-none', !input.value.trim());
  }

  input.addEventListener('input', toggleClear);

  clearX.addEventListener('click', () => {
    input.value = '';
    toggleClear();
    // Reset to full list (no ?q=)
    window.location.href = 'location.php';
  });

  toggleClear();
})();

