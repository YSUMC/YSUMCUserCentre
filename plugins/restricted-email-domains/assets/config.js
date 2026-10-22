(() => {
  function initialize() {
    document.querySelectorAll('[data-domain-form]').forEach(form => {
      const button = form.querySelector('button[type="submit"]');
      const message = form.querySelector('[data-domain-message]');
      form.addEventListener('submit', async event => {
        event.preventDefault();
        if (button.disabled) return;
        button.disabled = true;
        message.hidden = true;
        try {
          const domains = [...new Set(form.elements.domains.value.split(/\r?\n/).map(value => value.trim()).filter(Boolean))];
          const response = await fetch(form.action, {
            method: 'PUT', credentials: 'same-origin',
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': form.elements._token.value},
            body: JSON.stringify(domains),
          });
          if (!response.ok) {
            const result = await response.json();
            throw new Error(Object.values(result.errors || {}).flat().join('\n') || result.message || `HTTP ${response.status}`);
          }
          message.textContent = document.documentElement.lang.startsWith('zh') ? '已保存' : 'Saved';
          message.className = 'alert alert-success mt-3';
        } catch (error) {
          message.textContent = error.message;
          message.className = 'alert alert-danger mt-3';
        } finally {
          message.hidden = false;
          button.disabled = false;
        }
      });
    });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize);
  else initialize();
})();
