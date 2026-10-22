require('reflect-metadata');
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { AppService } = require('../dist/app.service');
const { OIDCAdapter } = require('../dist/oidc-adapter');
const { OIDCProviderService } = require('../dist/oidc-provider.service');

function fixture({ codeClient = '11', requestedClient = '11', approved = '["openid"]', requested = 'openid', missing = false, consumed = false, profileOwner = 7, revokedClient = false } = {}) {
  const state = { grant: null, results: [], consumed, saves: 0 };
  class Grant {
    constructor(data) { Object.assign(this, data); this.scopes = []; }
    addOIDCScope(scope) { this.scopes.push(scope); }
    addResourceScope() {}
    async save() { state.grant = this; state.saves++; }
  }
  const provider = {
    callback: () => () => {}, Grant,
    interactionDetails: async () => ({ params: { client_id: requestedClient, scope: requested } }),
    interactionFinished: async (_req, _res, result) => { state.results.push(result); },
  };
  const prisma = { client: {
    client: { findFirst: async () => revokedClient ? null : { id: requestedClient } },
    passportAuthCode: {
      // Deliberately return the mismatched row too: the service must verify it.
      findFirst: async () => missing ? null : { id: 'synthetic-code', client_id: codeClient, user_id: 7, scopes: approved },
      updateMany: async () => { if (state.consumed) return { count: 0 }; state.consumed = true; return { count: 1 }; },
    },
    user: { findFirst: async () => ({ uid: 7, verified: true, permission: 0 }) },
    codeIdToUUID: { findFirst: async () => ({ uuid: 'synthetic-uuid' }) },
    uUID: { findFirst: async () => ({ player: { uid: profileOwner } }) },
  } };
  const service = new AppService(prisma, { provider, siteUrl: 'https://offline.invalid' });
  return { state, run: (code = 'synthetic-code') => service.interactionCallback({ query: { code } }, {}) };
}

test('valid authorization preserves the approved scopes', async () => {
  const { state, run } = fixture({ approved: '["openid","email"]', requested: 'openid email' });
  await run();
  assert.equal(state.results[0].login.accountId, '7');
  assert.deepEqual(state.grant.scopes, ['openid', 'email']);
});

for (const [label, options] of [
  ['unknown code', { missing: true }],
  ['different client', { codeClient: '11', requestedClient: '22' }],
  ['unapproved scopes', { requested: 'openid email offline_access' }],
  ['malformed scopes', { approved: 'broken-json' }],
  ['non-array scopes', { approved: '{}' }],
  ['non-string scope entries', { approved: '[1]' }],
  ['consumed code', { consumed: true }],
  ['non-numeric client', { requestedClient: '11bad' }],
  ['overflowing client ID', { requestedClient: '18446744073709551616' }],
  ['revoked client', { revokedClient: true }],
  ['another user profile', { approved: '["openid","Yggdrasil.PlayerProfiles.Select"]', requested: 'openid Yggdrasil.PlayerProfiles.Select', profileOwner: 8 }],
]) {
  test(`rejects ${label} without creating a grant`, async () => {
    const { state, run } = fixture(options);
    await run();
    assert.equal(state.results[0].error, 'invalid_grant');
    assert.equal(state.saves, 0);
  });
}

test('concurrent callbacks consume a code only once', async () => {
  const { state, run } = fixture();
  await Promise.all([run(), run()]);
  assert.equal(state.saves, 1);
  assert.equal(state.results.filter(r => r.error === 'invalid_grant').length, 1);
});

test('malformed callback code never becomes an unfiltered database lookup', async () => {
  const { state, run } = fixture();
  await run(['bad']);
  assert.equal(state.results[0].error, 'invalid_grant');
});

test('Client lookup rejects revoked and malformed IDs, preserves active metadata', async () => {
  let revoked = true;
  let lookups = 0;
  const payload = { client_id: '11' };
  const adapter = new OIDCAdapter('Client', { client: { findFirst: async ({ where }) => {
    lookups++; assert.equal(where.id, 11n); return { revoked, payload };
  } } }, {});
  assert.equal(await adapter.find('11'), undefined);
  assert.equal(await adapter.find('11suffix'), undefined);
  assert.equal(lookups, 1);
  revoked = false;
  assert.deepEqual(await adapter.find('11'), payload);
});

test('device pages escape client metadata, codes and upstream error text', async () => {
  const service = Object.create(OIDCProviderService.prototype);
  service.siteUrl = 'https://offline.invalid';
  const injected = '<img src=x onerror="alert(1)">&';
  const ctx = { oidc: { client: { clientName: injected } } };
  const form = '<form id="provider-owned"></form>';
  await service.userCodeConfirmSource(ctx, form, null, null, injected);
  assert.ok(!ctx.body.includes(injected));
  assert.ok(ctx.body.includes('&lt;img'));
  assert.ok(ctx.body.includes(form));
  await service.userCodeInputSource(ctx, form, { description: injected }, { name: 'AbortedError' });
  assert.ok(!ctx.body.includes('<img src=x'));
  assert.ok(ctx.body.includes('&lt;img'));
});
