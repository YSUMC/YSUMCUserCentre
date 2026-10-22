const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const template = fs.readFileSync(path.join(__dirname, '../plugins/sitmc-launcher/views/admin/interop.twig'), 'utf8');
const code = template.match(/<script>([\s\S]*?)<\/script>/)[1].replace(/\{\{[\s\S]*?\}\}/g, 'message');
const settle = () => new Promise(resolve => setImmediate(resolve));

for (const firstSucceeds of [true, false]) {
  test(`publication requests serialize and preserve the final selection after ${firstSucceeds ? 'success' : 'failure'}`, async () => {
    const requests = [];
    let change, timer;
    const box = { checked: false, closest: () => null, addEventListener: (_, fn) => { change = fn; } };
    const form = { action: '/publish' };
    vm.runInNewContext(code, {
      document: { getElementById: id => id === 'interop-publications-form' ? form : {}, querySelectorAll: () => [box] },
      FormData: function () { this.selected = box.checked; },
      fetch: (_, options) => new Promise(resolve => requests.push({ selected: options.body.selected, resolve })),
      setTimeout: fn => { timer = fn; }, clearTimeout: () => { timer = null; },
    });
    box.checked = true; change(); timer();
    box.checked = false; change(); timer();
    assert.equal(requests.length, 1, 'a second request must not race the first write');
    requests[0].resolve({ ok: firstSucceeds, json: async () => ({ ok: firstSucceeds }) });
    await settle();
    assert.equal(requests.length, 2);
    assert.equal(requests[0].selected, true);
    assert.equal(requests[1].selected, false, 'queued write captures the latest selection');
    requests[1].resolve({ ok: true, json: async () => ({ ok: true }) });
    await settle();
    assert.equal(requests.length, 2, 'no redundant save loop');
  });
}
