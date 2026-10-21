const { test } = require('node:test');
const assert = require('node:assert/strict');
const { OIDCAdapter } = require('../dist/oidc-adapter');

function fixture(type = 'RefreshToken') {
  const state = {
    record: {
      payload: { accountId: '7', clientId: '2' },
      consumed: false,
      updated_at: new Date('2026-10-20T00:00:00Z'),
    },
    refresh: { access_token_id: 'access-1', revoked: false },
    access: { revoked: false },
  };
  const model = {
    findFirst: async () => state.record,
    update: async ({ data }) => Object.assign(state.record, data),
  };
  const prisma = {
    refreshToken: model,
    deviceCode: model,
    interaction: model,
    user: { findFirst: async () => ({ uid: 7 }) },
    passportRefreshToken: { findUnique: async () => state.refresh },
    passportAccessToken: { findUnique: async () => state.access },
  };
  return { state, adapter: new OIDCAdapter(type, prisma, {}) };
}

test('accepts a refresh token while both Passport records remain active', async () => {
  const { adapter, state } = fixture();
  assert.deepEqual(await adapter.find('refresh-1'), state.record.payload);
});

for (const [label, change] of [
  ['revoked refresh token', s => { s.refresh.revoked = true; }],
  ['missing refresh token', s => { s.refresh = null; }],
  ['revoked access token', s => { s.access.revoked = true; }],
  ['missing access token', s => { s.access = null; }],
]) {
  test(`rejects ${label} even when Janus still has its payload`, async () => {
    const { adapter, state } = fixture();
    change(state);
    assert.equal(await adapter.find('refresh-1'), undefined);
  });
}

test('returns consumption to the provider after a refresh token is used', async () => {
  const { adapter, state } = fixture();
  await adapter.consume('refresh-1');
  const result = await adapter.find('refresh-1');
  assert.equal(result.consumed, state.record.updated_at.getTime() / 1000);
  assert.equal(result.accountId, '7');
});

test('preserves consumption when looking up a device user code', async () => {
  const { adapter, state } = fixture('DeviceCode');
  state.record.consumed = true;
  assert.ok((await adapter.findByUserCode('TEST-CODE')).consumed);
});

test('preserves consumption when looking up an interaction UID', async () => {
  const { adapter, state } = fixture('Interaction');
  state.record.consumed = true;
  assert.ok((await adapter.findByUid('interaction-1')).consumed);
});
