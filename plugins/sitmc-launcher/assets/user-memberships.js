(() => {
  'use strict';

  const container = document.querySelector('.content > .container-fluid');
  if (!container || !globalThis.blessing) return;
  const endpoint = `${blessing.base_url.replace(/\/$/, '')}/admin/launcher/users`;
  const cache = new Map();
  const pending = new Set();
  let scheduled = false;
  let departments = {};
  let opening = false;

  function element(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
  }

  async function request(url, options = {}) {
    const response = await fetch(url, {
      ...options,
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
      },
    });
    const body = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = response.status === 401 || response.status === 419
        ? '登录已过期，请刷新页面并重新登录。'
        : Object.values(body.errors || {}).flat().join(' ') || body.message || `请求失败（${response.status}）`;
      throw new Error(message);
    }
    return body;
  }

  function load(uids) {
    const params = new URLSearchParams();
    uids.forEach(uid => params.append('uids[]', uid));
    return request(`${endpoint}/data?${params}`);
  }

  // The core page supports both table and card modes and replaces its users
  // asynchronously. Mount only plugin-owned children, without patching React.
  function locations() {
    const found = [];
    container.querySelectorAll('.card-body table tbody > tr').forEach(row => {
      const uid = row.cells[0]?.textContent.trim();
      if (row.cells.length !== 8 || !/^[1-9]\d*$/.test(uid || '')) return;
      const host = row.cells[4];
      const signature = Array.from(host.childNodes)
        .filter(node => node.nodeType === Node.TEXT_NODE).map(node => node.textContent).join('');
      found.push({ uid, host, signature });
    });
    container.querySelectorAll('.card-body .info-box').forEach(card => {
      const uid = card.querySelector('img.bs-avatar')?.getAttribute('src')?.match(/\/avatar\/user\/(\d+)(?:[/?#]|$)/)?.[1];
      const host = card.querySelector('.info-box-content');
      if (!uid || !host) return;
      const signature = card.querySelector('.row.m-2 > div:nth-child(2) span')?.textContent || '';
      found.push({ uid, host, signature });
    });
    return found;
  }

  function render(root, record, uid) {
    if (root.membershipRecord === record) return;
    root.membershipRecord = record;
    root.replaceChildren();
    if (!record) {
      root.append(element('small', 'text-muted', '正在加载用户等级…'));
      return;
    }
    if (record.error) {
      root.append(element('small', 'text-danger', record.error));
      const retry = element('button', 'btn btn-link launcher-membership-edit', '重新加载');
      retry.type = 'button';
      retry.onclick = () => { cache.delete(uid); schedule(); };
      root.append(retry);
      return;
    }
    root.append(element('small', 'launcher-membership-heading', '用户等级 / 部门'));
    const tags = element('div');
    record.membership.tags.forEach(tag => tags.append(element('span', 'badge badge-info', tag)));
    root.append(tags);
    if (record.editable) {
      const button = element('button', 'btn btn-link launcher-membership-edit', '调整等级和部门');
      button.type = 'button';
      button.onclick = () => openEditor(uid, button);
      root.append(button);
    }
  }

  function schedule() {
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(scan);
  }

  function scan() {
    scheduled = false;
    const missing = new Map();
    locations().forEach(({ uid, host, signature }) => {
      let root = Array.from(host.children).find(node => node.classList.contains('launcher-membership'));
      if (!root) {
        root = element('div', 'launcher-membership');
        host.append(root);
      }
      const entry = cache.get(uid);
      const current = entry?.signature === signature ? entry.record : null;
      render(root, current, uid);
      if (!current && !pending.has(uid)) missing.set(uid, signature);
    });
    if (!missing.size) return;
    missing.forEach((_, uid) => pending.add(uid));
    load([...missing.keys()]).then(body => {
      departments = body.departments;
      const users = new Map(body.users.map(user => [String(user.uid), user]));
      missing.forEach((signature, uid) => cache.set(uid, {
        signature, record: users.get(uid) || { error: '账号已不存在，请刷新用户列表。' },
      }));
    }).catch(error => {
      missing.forEach((signature, uid) => cache.set(uid, { signature, record: { error: error.message } }));
    }).finally(() => {
      missing.forEach((_, uid) => pending.delete(uid));
      schedule();
    });
  }

  async function openEditor(uid, button) {
    if (opening || document.querySelector('.launcher-membership-dialog')) return;
    opening = true;
    button.disabled = true;
    try {
      // Reload before editing, so options reflect current account permissions.
      const body = await load([uid]);
      const user = body.users[0];
      if (!user?.editable) throw new Error('当前无权调整此账号的等级，请刷新用户列表。');
      departments = body.departments;
      showEditor(user, button);
    } catch (error) {
      const root = button.closest('.launcher-membership');
      const previous = root?.querySelector('[role="alert"]');
      previous?.remove();
      const message = element('small', 'text-danger d-block', error.message);
      message.setAttribute('role', 'alert');
      root?.append(message);
    } finally {
      opening = false;
      button.disabled = false;
    }
  }

  function showEditor(user, opener) {
    const dialog = element('dialog', 'launcher-membership-dialog');
    const title = element('h2', '', '调整用户等级和部门');
    title.id = 'launcher-membership-title';
    dialog.setAttribute('aria-labelledby', title.id);
    const form = element('form');
    const role = element('select', 'form-control');
    role.id = 'launcher-membership-role';
    role.required = true;
    Object.entries(user.roles).forEach(([value, label]) => role.add(new Option(label, value)));
    role.value = user.membership.role;
    const department = element('select', 'form-control');
    department.id = 'launcher-membership-department';
    department.add(new Option('请选择一个部门', ''));
    Object.entries(departments).forEach(([value, label]) => department.add(new Option(label, value)));
    department.value = user.membership.department || '';
    const field = (label, input) => {
      const group = element('div', 'form-group');
      const caption = element('label', '', label);
      caption.htmlFor = input.id;
      group.append(caption, input);
      return group;
    };
    const departmentField = field('部门', department);
    const syncDepartment = () => {
      const needsDepartment = ['member', 'minister'].includes(role.value);
      departmentField.hidden = !needsDepartment;
      department.required = needsDepartment;
      department.disabled = !needsDepartment;
    };
    role.addEventListener('change', syncDepartment);
    syncDepartment();
    const feedback = element('p', 'text-danger mt-3 mb-0');
    feedback.setAttribute('role', 'alert');
    const cancel = element('button', 'btn btn-secondary', '取消');
    cancel.type = 'button';
    cancel.onclick = () => dialog.close();
    const save = element('button', 'btn btn-primary', '保存');
    save.type = 'submit';
    const actions = element('div', 'launcher-membership-actions');
    actions.append(cancel, save);
    form.append(field('用户等级', role), departmentField,
      element('small', 'text-muted', '社员和部长必须选择一个部门；等级与部门标签同步用于启动器展示和内容下发。'), feedback, actions);
    dialog.append(title, element('p', 'text-muted mb-0', `${user.nickname} · UID ${user.uid}`), form);
    let saving = false;
    dialog.addEventListener('cancel', event => { if (saving) event.preventDefault(); });
    dialog.addEventListener('close', () => { dialog.remove(); if (opener.isConnected) opener.focus(); });
    form.addEventListener('submit', async event => {
      event.preventDefault();
      if (saving) return;
      saving = true;
      save.disabled = cancel.disabled = role.disabled = department.disabled = true;
      save.textContent = '正在保存…';
      feedback.textContent = '';
      try {
        const result = await request(`${endpoint}/${user.uid}`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' },
          body: JSON.stringify({ role: role.value, department: ['member', 'minister'].includes(role.value) ? department.value : null }),
        });
        // Update labels immediately, then use core's existing search submit to
        // refresh its permission controls while retaining search and pagination.
        locations().filter(location => location.uid === String(user.uid)).forEach(({ signature }) => {
          cache.set(String(user.uid), { signature, record: result.user });
        });
        dialog.close();
        blessing.notify?.toast?.success(result.message);
        container.querySelector('.card-header form')?.requestSubmit();
        schedule();
      } catch (error) {
        feedback.textContent = error.message;
      } finally {
        saving = false;
        save.disabled = cancel.disabled = role.disabled = false;
        save.textContent = '保存';
        syncDepartment();
      }
    });
    document.body.append(dialog);
    dialog.showModal();
    role.focus();
  }

  new MutationObserver(schedule).observe(container, { childList: true, subtree: true, characterData: true });
  schedule();
})();
