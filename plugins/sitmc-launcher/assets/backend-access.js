(() => {
  'use strict';
  const source = document.getElementById('launcher-backend-access');
  if (!source) return;
  const access = JSON.parse(source.textContent);
  globalThis.launcherBackendAccess = access;
  if (access.super) return;
  const base = new URL(blessing.base_url);
  const basePath = base.pathname.replace(/\/$/, '');
  const backendPath = href => {
    try {
      const url = new URL(href, location.href);
      if (url.origin !== base.origin || !url.pathname.startsWith(`${basePath}/`)) return null;
      const path = url.pathname.slice(basePath.length + 1).replace(/\/$/, '');
      return path === 'admin' || path.startsWith('admin/') ? path : null;
    } catch { return null; }
  };
  const level = path => {
    if (path === 'admin/plugins/config/sitmc-launcher') return 0;
    for (const module of Object.values(access.modules)) {
      if (path === module.path || module.prefixes.some(prefix => path === prefix || path.startsWith(`${prefix}/`))) return module.level;
    }
    return 0;
  };
  const content = document.querySelector('.content');
  if (access.readOnly && content) {
    const notice = document.createElement('div');
    notice.className = 'alert alert-info';
    notice.textContent = '当前页签为只读权限，可以查看和搜索，不能提交更改。';
    // Outside the React mount, so list updates cannot remove the notice.
    content.prepend(notice);
  }
  const isMutation = control => {
    if (!content?.contains(control)) return false;
    if (control.closest('.card-header form, .pagination, .btn-group-toggle')) return false;
    if (control.matches('button')) return true;
    return control.matches('a.dropdown-item') || !!control.querySelector('.fa-edit, .fa-trash, .fa-toggle-on, .fa-toggle-off');
  };
  let scheduled = false;
  const refresh = () => {
    scheduled = false;
    document.querySelectorAll('a[href]').forEach(link => {
      const path = backendPath(link.href);
      if (path && level(path) === 0) link.hidden = true;
    });
    // Web CLI exposes bulk admin commands; server routes still enforce policy.
    document.getElementById('launch-cli')?.setAttribute('hidden', '');
    content?.querySelector('input[name="option"][value="customJsCss"]')?.closest('form')
      ?.querySelectorAll('input, select, textarea, button').forEach(control => { control.disabled = true; });
    if (!access.readOnly || !content) return;
    content.querySelectorAll('form').forEach(form => {
      if (form.method.toLowerCase() === 'get') return;
      form.querySelectorAll('input, select, textarea, button').forEach(control => { control.disabled = true; });
    });
    content.querySelectorAll('button, a').forEach(control => {
      if (!isMutation(control)) return;
      control.setAttribute('aria-disabled', 'true');
      if (control.matches('button')) control.disabled = true;
      else control.hidden = true;
    });
  };
  const schedule = () => {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(refresh);
  };
  if (access.readOnly) {
    content?.addEventListener('click', event => {
      const control = event.target instanceof Element ? event.target.closest('button, a') : null;
      if (control && isMutation(control)) { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
    content?.addEventListener('submit', event => {
      if (event.target.method.toLowerCase() !== 'get') { event.preventDefault(); event.stopImmediatePropagation(); }
    }, true);
  }
  new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true });
  refresh();
})();
