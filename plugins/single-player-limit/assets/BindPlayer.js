(() => {
  function initialize() {
    const form = document.getElementById('bind-player-form');
    if (!form) return;
    const button = form.querySelector('button[type="submit"]');
    const error = form.querySelector('[data-bind-error]');
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (button.disabled || !form.reportValidity()) return;
      button.disabled = true;
      error.hidden = true;
      try {
        const response = await fetch(form.action, {
          method: 'POST', credentials: 'same-origin',
          headers: {Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
          body: new FormData(form),
        });
        const result = await response.json();
        if (!response.ok || result.code !== 0) {
          throw new Error(result.message || Object.values(result.errors || {}).flat().join('\n') || `HTTP ${response.status}`);
        }
        window.location.assign(form.dataset.successUrl);
      } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
        button.disabled = false;
      }
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
