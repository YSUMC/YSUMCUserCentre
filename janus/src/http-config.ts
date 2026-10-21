import { NestExpressApplication } from '@nestjs/platform-express';
import { Request } from 'express';
import { RoutesWithoutCORS } from './blessing.types';

export const JANUS_PREFIX = '/api/janus';

/** Express receives the original prefix from nginx; oidc-provider needs a relative path. */
export function providerPath(originalUrl: string): string {
  if (originalUrl === JANUS_PREFIX) return '/';
  if (originalUrl.startsWith(`${JANUS_PREFIX}?`)) return `/${originalUrl.slice(JANUS_PREFIX.length)}`;
  if (originalUrl.startsWith(`${JANUS_PREFIX}/`)) return originalUrl.slice(JANUS_PREFIX.length);
  throw new Error('Request is outside the Janus mount point');
}

export function configureHttpApp(app: NestExpressApplication) {
  app.enableCors((req: Request, callback) => {
    if (req.path !== JANUS_PREFIX && !req.path.startsWith(`${JANUS_PREFIX}/`)) {
      callback(null, { origin: false });
      return;
    }
    const route = providerPath(req.originalUrl).split(/[/?]/)[1];
    callback(null, { origin: route in RoutesWithoutCORS ? false : '*' });
  });
  // The deployment example places nginx on this host. Never trust arbitrary peers.
  app.set('trust proxy', 'loopback');
  app.setGlobalPrefix(JANUS_PREFIX.slice(1));
}
