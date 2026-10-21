# Janus for Blessing Skin

Janus 是 [Blessing Skin Server](https://github.com/bs-community/blessing-skin-server) 的外挂 Yggdrasil Connect 服务端，基于 [NestJS](https://nestjs.com) 框架和 [oidc-provider](https://github.com/panva/node-oidc-provider) 库编写。

由于 Laravel 框架缺乏合适的 OpenID Connect 服务端扩展包，故采取这种外挂 OpenID Connect 服务端的方式为 Blessing Skin Server 实现 Yggdrasil Connect。

Janus 需要与 Blessing Skin Server 使用同一个 MySQL/MariaDB 数据库。不支持 PostgreSQL 和 SQLite 数据库。

## 环境需求

- Node.js >= 22.12.0
- Blessing Skin Server >= 6
    - 需要安装 [Yggdrasil Connect](https://github.com/bs-community/blessing-skin-plugins/blob/master/plugins/yggdrasil-connect) 插件，可在插件市场中下载
        - 该插件不需要也不可以与原版 Yggdrasil API 插件同时启用，但插件数据可以通用
        - 在安装完该插件后，请务必阅读该插件的 [README](https://github.com/bs-community/blessing-skin-plugins/blob/master/plugins/yggdrasil-connect/README.md)，了解如何配置该插件

## 部署指南

### YSUMC 本地构建

在 `janus` 目录使用 Node.js >=22.12 和 npm 10.9.2。仓库已修复锁文件，可用 `corepack npm@10.9.2 ci` 安装；没有 Corepack 时使用同版本 npm。不要改用参考站的 `.env`、密钥或客户端 ID。

1. 将 `prisma/schema.prisma.example` 复制为 `prisma/schema.prisma`。示例无表前缀；若现有 PHP 数据库使用表前缀，应先核对所有 `@@map` 和表结构。不要直接向既有数据库执行 `prisma migrate reset`、`db push` 或初始建表 SQL。
2. 执行 `npm exec -- prisma generate`，然后 `npm run build`。
3. 执行 `npm test` 检查 HTTP 挂载路径、查询参数保留、CORS、userinfo 重定向和环境参数校验。测试用替代服务，不需要数据库，也不代表设备授权已联调。

### YSUMC 运行配置

复制 `.env.example` 为 `.env`，填写与 PHP 共用的 MySQL 配置及公开客户端 `SHARED_CLIENT_ID`；`ISSUER=https://skin.ysumc.net/api/janus`，`BS_SITE_URL=https://skin.ysumc.net`。应用根据 `DB_*` 自动构造并转义连接串，密码内的 `@`、`#` 等字符无需手动拼进 URL。

将与 PHP OAuth 相匹配的私钥安全配置到 `janus/oauth-private.key`，确认 PHP 已安装并启用兼容的 Yggdrasil Connect 插件，按照上游步骤建立公开客户端和个人访问客户端。密钥及 `.env` 不纳入 Git。

执行 `npm run start:prod` 时以 `janus` 为工作目录。服务仅监听 `127.0.0.1:3000`，由同机 Nginx 提供 TLS；使用 [Nginx 示例](../deploy/nginx.conf.example)，保留 `/api/janus` 前缀。如果采用容器或异机代理，必须另行配置明确的监听地址和可信代理网段，不能直接复用本机配置。

启动后检查 discovery 的 issuer、端点及 shared_client_id，再用真实游戏账号完成设备授权、userinfo、刷新和进服。完整部署仍依赖数据库结构、PHP 运行依赖、插件、OAuth 密钥和 TLS；目前的本地构建测试不包含这些外部服务。

请查看 [Wiki - 部署指南](https://github.com/bs-community/janus/wiki/%E9%83%A8%E7%BD%B2%E6%8C%87%E5%8D%97)。

Janus **不是** 开箱即用的，需要手动构建。部署 Janus 并不难，但最好有一定的运维经验。

## 公共客户端支持

应用无需进行特殊设置，即可使用 Janus 通过 Authorization Code Grant 配合 Client Secret 获取 Access Token 和 ID Token。

但若要使用 Device Authorization Grant 等公共客户端使用的授权方式，则需要在应用设置中为应用启用公共客户端支持。请查看 [Wiki - 为应用启用公共客户端支持](https://github.com/bs-community/janus/wiki/%E4%B8%BA%E5%BA%94%E7%94%A8%E5%90%AF%E7%94%A8%E5%85%AC%E5%85%B1%E5%AE%A2%E6%88%B7%E7%AB%AF%E6%94%AF%E6%8C%81)。

## 版权信息

Copyright 2025-present Blessing Skin Team. All rights reserved. Open source under the MIT license.

_Disclaimer：某站产品经理自己写代码的原则就是代码和人有一个能跑就行，自然有些代码很粗糙很难看很低效。如果你看着哪里的代码不爽，欢迎直接重构并 PR。_
