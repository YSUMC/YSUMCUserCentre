import { NestFactory } from '@nestjs/core';
import { AppModule } from './app.module';
import { NestExpressApplication } from '@nestjs/platform-express';
import { configureHttpApp } from './http-config';

async function bootstrap() {
  BigInt.prototype['toJSON'] = function () {
    const int = Number(this);
    return Number.isSafeInteger(int) ? int : this.toString();
  };

  const app = await NestFactory.create<NestExpressApplication>(AppModule);

  /*
    > The Token Endpoint ... and any other endpoints directly accessed by Clients SHOULD support the use of Cross-Origin Resource Sharing (CORS) ...
    > The use of CORS at the Authorization Endpoint is NOT RECOMMENDED as it is redirected to by the client and not directly accessed.
    https://openid.net/specs/openid-connect-discovery-1_0.html#ProviderMetadata
  */
  configureHttpApp(app);
  await app.listen(process.env.PORT ?? 3000, '127.0.0.1');
}
bootstrap();
