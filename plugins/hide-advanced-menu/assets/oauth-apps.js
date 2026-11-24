(() => {
  const root = document.getElementById('admin-oauth-apps');
  if (!root) return;
  const states = new Map();
  function hide(button, state) {
    clearTimeout(state.timer);
    state.shown = false;
    state.cell.querySelector('[data-secret-value]').textContent = '••••••••';
    button.textContent = '查看密钥';
  }
  root.querySelectorAll('[data-reveal-url]').forEach(button => {
    const state = { cell: button.closest('[data-oauth-secret]'), shown: false, timer: null };
    states.set(button, state);
    button.addEventListener('click', async () => {
      if (state.shown) { hide(button, state); return; }
      const error = state.cell.querySelector('[data-secret-error]');
      error.textContent = '';
      button.disabled = true;
      try {
        const response = await fetch(button.dataset.revealUrl, {
          method: 'POST', credentials: 'same-origin', cache: 'no-store',
          headers: { Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content }
        });
        if (!response.ok) {
          const data = await response.json().catch(() => ({}));
          throw new Error(data.message || `无法读取密钥（${response.status}），请检查权限或重新登录。`);
        }
        const data = await response.json();
        if (typeof data.client_secret !== 'string') throw new Error('返回的密钥格式不正确。');
        if (document.hidden) return;
        state.cell.querySelector('[data-secret-value]').textContent = data.client_secret;
        state.shown = true;
        button.textContent = '隐藏密钥';
        state.timer = setTimeout(() => hide(button, state), 60000);
      } catch (e) {
        error.textContent = e.message || '读取失败，请重试。';
      } finally {
        button.disabled = false;
      }
    });
  });
  const hideAll = () => states.forEach((state, button) => hide(button, state));
  window.addEventListener('pagehide', hideAll);
  document.addEventListener('visibilitychange', () => { if (document.hidden) hideAll(); });
})();
