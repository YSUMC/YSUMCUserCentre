require('reflect-metadata');
const { test, before, after } = require('node:test');
const assert = require('node:assert/strict');
const { Test } = require('@nestjs/testing');
const request = require('supertest');
const { AppController } = require('../dist/app.controller');
const { AppService } = require('../dist/app.service');
const { configureHttpApp, providerPath } = require('../dist/http-config');
const { validate } = require('../dist/env-validation');

let app;
before(async () => {
  const module = await Test.createTestingModule({
    controllers: [AppController],
    providers: [{
      provide: AppService,
      useValue: {
        callback: async (req, res) => res.json({ path: req.url }),
        getSiteUrl: () => 'https://skin.ysumc.net',
      },
    }],
  }).compile();
  app = module.createNestApplication();
  configureHttpApp(app);
  await app.init();
});
after(async () => app?.close());

test('preserves query strings and strips only the leading mount path', async () => {
  const path = '/api/janus/.well-known/openid-configuration?return=/api/janus/token';
  const response = await request(app.getHttpServer()).get(path).expect(200);
  assert.equal(response.body.path, '/.well-known/openid-configuration?return=/api/janus/token');
  assert.equal(providerPath('/api/janus?x=1'), '/?x=1');
  assert.throws(() => providerPath('/api/janus-other/token'));
});

test('token endpoint supports CORS while interactive authorization does not', async () => {
  const token = await request(app.getHttpServer()).post('/api/janus/token')
    .set('Origin', 'https://client.example').expect(200);
  assert.equal(token.headers['access-control-allow-origin'], '*');
  const auth = await request(app.getHttpServer()).get('/api/janus/auth')
    .set('Origin', 'https://client.example').expect(200);
  assert.equal(auth.headers['access-control-allow-origin'], undefined);
});

test('unrelated paths remain 404 instead of causing CORS middleware errors', async () => {
  await request(app.getHttpServer()).get('/health').expect(404);
});

test('userinfo keeps the concrete route ahead of the provider catch-all', async () => {
  await request(app.getHttpServer()).get('/api/janus/userinfo')
    .expect(303).expect('Location', 'https://skin.ysumc.net/yggc/userinfo');
});

test('invalid issuer and TTL produce configuration errors without URL parser crashes', () => {
  const config = {
    PORT: '3000', DB_HOST: 'localhost', DB_PORT: '3306',
    DB_USERNAME: 'test', DB_PASSWORD: 'test', DB_NAME: 'test',
    BS_SITE_URL: 'https://skin.ysumc.net', ISSUER: 'https://skin.ysumc.net/api/janus',
    SHARED_CLIENT_ID: 'test', TOKEN_EXPIRES_IN_1: '3600', TOKEN_EXPIRES_IN_2: '7200',
    DEVICE_CODE_EXPIRES_IN: '600', GRANT_EXPIRES_IN: '7200',
  };
  assert.equal(validate(config).PORT, 3000);
  const url = new URL(validate({ ...config, DB_PASSWORD: 'p@ss:/#%word' }).DB_CONNECTION_STRING);
  assert.equal(decodeURIComponent(url.password), 'p@ss:/#%word');
  assert.equal(url.hostname, 'localhost');
  assert.throws(() => validate({ ...config, ISSUER: 'invalid' }), /must be a secure URL/);
  assert.throws(() => validate({ ...config, ISSUER: 'ftp://localhost' }), /must be a secure URL/);
  assert.throws(() => validate({ ...config, DEVICE_CODE_EXPIRES_IN: '-1' }), /must not be less than 1/);
});
