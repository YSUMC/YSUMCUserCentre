# YSUMC 用户中心部署记录与操作说明

**最新状态（2026-10-22）：PHP 升级、安全修复和 launcher 互通端点已合并至 `YSUMC-dev` 并部署，生产使用 PHP 8.5.12、Node 26.12.1，网站已恢复访问。目录、测试证据和回滚方法见 [本次生产发布记录](php85/REVIEW-RELEASE-20261022.md)。以下保留此前 PHP 8.1 部署阶段的历史记录，不代表当前运行状态。**

本目录作为根目录 `docs/YSUMC系统复用开发文档.md` 的部署补充。当前服务器目录为 `/www/wwwroot/ysumcusercentre`，宝塔网站为 `skin.ysumc.net`，PHP 版本为 8.1，运行目录为 `/public`。此前的 `/data/program/ysumcusercentre` 已迁移，过程与回滚位置见 [宝塔迁移记录](BAOTA-20261021.md)。

2026-10-21 已重新编译并安装 PHP 8.1.34 加官方 CVE-2026-109618 / CVE-2026-109619 应急回移补丁，替换原 8.1.32。按用户要求，网站仍处于宝塔停站状态，PHP-FPM 验证完成后也已停止。补丁来源、校验值、测试与回滚记录见 [PHP 8.1 安全补丁部署记录](PHP81-SECURITY-20261021.md)；原版 8.1.34 本身不包含这次补丁。

可复用源码、补丁与替换脚本见 [PHP 8.1 修复包说明](php81-repair-kit/README.md)。已在隔离目录编译 PHP 8.5.12 并核对当前依赖，部署模板、实测卡点及迁移步骤见 [PHP 8.5 升级评估](php85-assessment/README.md)。当前应用存在 Carbon 致命错误和 Composer 版本约束，尚不能直接切换到 PHP 8.5。

## 范围与当前状态

2026-10-21 的启动器 1.0.2 配套公告接口已部署：`GET /api/launcher/update/releases` 返回后台已启用的正式版说明，按版本倒序并合并同版本的多平台记录，不依赖安装包是否托管在本站。PHP 语法及 48 项合同检查通过，公网响应已确认包含 1.0.1 和 1.0.0 的后台公告。原有动态更新接口仍向 1.0.0 返回 1.0.1，向 1.0.1/1.0.2 返回 204。三个原文件备份位于 `.deploy/launcher-1.0.2-updates/backup/`，未修改发布数据、站点环境配置或 Blessing Skin 声明；回滚时恢复三个文件、清理路由缓存并重载 PHP-FPM 8.1。

2026-10-21 的启动器 1.0.1 配套修复已部署：`GET/POST /api/launcher/curseforge/v1/...` 验证 YSUMC 会话后代理官方 CurseForge 元数据，官方 Key 仅从服务器 `.env` 的 `YSUMC_CURSEFORGE_API_KEY` 读取，不下发给启动器。接口限定路径与只读操作，不接受任意目标地址，也不向 CurseForge 转发玩家令牌。分类、搜索、批量项目查询已通过实际 HTTPS 会话验证；匿名请求返回 401，其他游戏的查询返回 400。43 项合同检查通过。

公开 `/textures/{hash}` PNG 已补充跨域响应头，验证披风返回 200 与 `Access-Control-Allow-Origin: *`。此次仅重载宝塔 PHP-FPM 8.1，没有修改 Nginx、数据库或 Blessing Skin 声明。原文件与环境配置备份在 `.deploy/launcher-1.0.1-fixes/backup/`，目录仅 root 可访问。回滚代理时应同时恢复对应配置与插件路由；服务器 Key 不应复制回任何启动器构建环境。

- 主机：`root@103.39.66.86`；域名：`skin.ysumc.net`。
- 初次部署建立独立数据库；旧站账号和纹理随后已迁入，详见 [数据迁移记录](MIGRATION-20261021.md)。
- 不开发社员审核、招新绑定、学校 SSO、活动签到、官网登录或社员准入限制。
- 邮件暂未配置，使用 `MAIL_MAILER=log`。邮件验证及找回密码不能视为已向用户发送。
- Janus 当前要求账号数据库中的 `verified=true`；站点关闭“强制邮箱验证”并不会改变这一要求。因此初始管理员可进行认证联调，但新注册账号的启动器授权还需先解决邮箱验证。未自动将新用户标记为已验证。
- 新站 PHP、Janus、独立数据库已启动；登录页、注册页及其本地 JS/CSS 返回正常。
- 已通过新服务器的 HTTPS 验证账号登录、Secure 会话 Cookie、用户页面、启动器公开 API、未携带 Bearer Token 时皮肤 API 的 401 响应，以及 OAuth 设备码申请和待授权响应。
- 公网 DNS、证书、浏览器完整授权、真实 Minecraft 登录分别验收；内部检查成功不能代替这些验收。
- 2026-10-21（北京时间），权威 DNS 与公共 DNS 已返回 `103.39.66.86`；Let's Encrypt 证书已签发并启用，过期时间为 2027-01-18 16:21:54 UTC。部分递归 DNS 仍可能短暂缓存旧地址。
- 已安装专用证书续期 reload hook；服务器已有 `certbot.timer`。续期演练及完整授权的最终结果见下方验收记录。
- 本次修复 Janus 对 Passport 撤销状态的遗漏：已撤销或已删除的刷新凭据不能重新换取访问令牌；同时将已消费状态返回给 OIDC provider，用于拒绝一次性凭据重放。

## 进程与数据位置

| 组件 | 地址或路径 | 管理方式 |
| --- | --- | --- |
| Laravel / Blessing Skin | `/tmp/php-cgi-81.sock` | 宝塔 PHP 8.1，`php-fpm-81.service` |
| Janus | `127.0.0.1:13010` | `ysumc-janus.service` |
| 内部检查站点 | `127.0.0.1:13011`，Host 为 `skin.ysumc.net` | 宝塔 Nginx |
| 独立 MariaDB 10.11 | `127.0.0.1:13306` | `deploy/compose.yaml` |
| 数据库文件 | `.runtime/mysql/` | Docker 绑定目录 |
| 网站配置 | `.env` | root:www，640 |
| Janus 配置 | `janus/.env` | root:www，640 |
| 数据库凭据 | `.deploy/database.env` | root，600 |
| 初始管理账号 | `.deploy/bootstrap-admin.json` | root，600 |
| 初始化元数据 | `.deploy/initialized.json` | root，600 |

以上路径均相对部署根目录。`.deploy`、`.runtime`、环境文件及私钥不进入 Git。数据库仅监听本机，不复用或修改宝塔现有数据库。

网站使用宝塔管理的 PHP 8.1，通过 `public/.user.ini` 设置本站的 `open_basedir`、256M 内存、512M 上传和 520M POST 限制，模板为 `deploy/php.user.ini`。全局 `php.ini` 已关闭 `session.upload_progress.enabled`，本站及备用 PHP 池模板也保持关闭。原 `ysumc-php.service` 已停用并归档；仓库中的独立 PHP 服务及池配置仅作为备用模板，不要与宝塔部署步骤混用。正式升级 PHP 前需验证此版本 Blessing Skin、Laravel 和插件的兼容性。

2026-10-21 按要求应用上传进度功能的临时缓解配置：在 `/www/server/php/81/etc/php.ini` 设置 `session.upload_progress.enabled = Off`，校验通过后平滑重载 `php-fpm-81.service`。已通过实际 FastCGI 请求确认该文件为 PHP-FPM 加载的配置，且全局值与本站值均为关闭；登录页和启动器 manifest 返回 200。原文件与验证结果保存在 `.deploy/backups/20261021T062302Z-disable-upload-progress/`。此次未升级 PHP，不将关闭该功能视为所有 RCE 漏洞已经修复。该选项说明见 [PHP 官方文档](https://www.php.net/manual/en/session.configuration.php#ini.session.upload-progress.enabled)。

## 上游运行时恢复

精确版本、下载地址和 SHA-256 见 `upstream-sources.json`。本次补齐官方 Blessing Skin 6.0.2 Composer 清单和 Yggdrasil Connect 6.2.0 插件。

官方发布 ZIP 用于恢复缺失运行时；保留仓库内定制的应用代码与主题。首次部署需恢复：

```text
vendor/
public/app/
resources/views/assets/*.twig
storage/（仅首次初始化所需文件）
bootstrap/cache/（目录与初始化文件）
```

`resources/views/assets/*.twig` 是官方前端构建产生的资源引用，缺少时页面可能返回 200 却不加载登录表单。恢复后执行 `artisan twig:clean`，并检查页面引用的 JS、CSS 和动态分块资源。

Janus 授权页原先引用的 Bootstrap 地址实际返回 404，本次改为使用同站官方 6.0.2 构建中的 `/app/style.7eb5d06.css`，再加载 YSUMC 主题。升级上游前端构建时，需要同步更新该资源引用。

不要用发布包覆盖已有站点的 `storage`、环境配置或 OAuth 密钥。`public/meta.js` 本次与官方版本一致，无需替换。

## 初始化与构建

首次初始化前准备独立数据库配置、新站 `APP_KEY`、OAuth 密钥及 root 专属的 `.deploy/bootstrap-admin.json`。该 JSON 包含 `email`、`password`；密码要求 24–32 位随机字符。不要把密码写进命令行、Git 或聊天。

```bash
cd /www/wwwroot/ysumcusercentre
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d --wait
php -d open_basedir=/www/wwwroot/ysumcusercentre:/tmp:/proc deploy/bootstrap-site.php
cd janus
npm ci
npx prisma generate
npm run build
```

`bootstrap-site.php` 仅允许 CLI，并检查数据库名 `ysumc_skin` 和端口 `13306`。已有账号但没有安装锁时拒绝重新安装；Janus 部分表已存在时拒绝继续，需先核查现场。

Janus 只补充六张 `yggc_*` 表，不能在已经完成 Blessing Skin 迁移的数据库上重复执行 Janus 的完整初始建库 SQL。

启用 `yggdrasil-connect` 与仓库的 `sitmc-launcher` 插件；不同时启用旧版 Yggdrasil API 插件。插件目录名兼容原项目，站点功能和品牌按 YSUMC 配置。

当前公开 OAuth 客户端 ID 为 `2`，设备授权范围为：

```text
openid offline_access Yggdrasil.PlayerProfiles.Select Yggdrasil.Server.Join
```

Janus 通过 `/yggc/client/public` 回调标记识别公开客户端。Passport 数据库中的服务端 secret 保留在服务器；启动器无需携带此 secret。

初始管理邮箱为占位地址 `bootstrap@ysumc.invalid`。部署所有者通过 SSH 在服务器读取 `.deploy/bootstrap-admin.json`，登录后立即改为自己的邮箱和密码。邮件服务配置完成前，不能依赖邮件找回账号。

## HTTPS 切换

1. 权威 DNS 上将 `skin.ysumc.net` 的默认线路 A 记录设置为 `103.39.66.86`；不要保留指向其他主机的 AAAA 记录。
2. 使用 `nginx-domain-pending.conf` 提供 ACME 验证目录，其余请求保持“正在部署”。
3. 确认公网能读取挑战文件，再使用服务器现有 Certbot 账户签发：

```bash
certbot certonly --webroot \
  -w /www/wwwroot/ysumcusercentre/public \
  -d skin.ysumc.net --cert-name skin.ysumc.net --non-interactive
```

4. 宝塔中创建 PHP 8.1 网站 `skin.ysumc.net`，网站目录设为 `/www/wwwroot/ysumcusercentre`，运行目录设为 `/public`。证书存在后，将本目录 `nginx-domain.conf` 安装到 `/www/server/panel/vhost/nginx/skin.ysumc.net.conf`，将 `nginx-rewrite.conf` 安装到 `/www/server/panel/vhost/rewrite/skin.ysumc.net.conf`，将 `php.user.ini` 安装到站点 `public/.user.ini`。先备份配置，再执行 `nginx -t`，成功后才 reload。
5. 当前域名配置已由宝塔网站管理，不要同时启用旧的 `ysumc-usercentre-domain.conf`，避免重复域名。早期同名但服务其他域名的历史配置已不存在；迁移时已检查没有覆盖其他站点。
6. 将 `certbot-deploy-hook.sh` 安装到 `/etc/letsencrypt/renewal-hooks/deploy/ysumc-usercentre.sh`，权限为 755。该 hook 只处理本域名证书，先校验 Nginx 再 reload。验证 `certbot.timer` 与本域名续期演练。

应使用 HTTPS 检查 OIDC discovery 的 issuer、authorization/token/device endpoints；这些地址全部应指向 `https://skin.ysumc.net/api/janus`。同时验证 Secure Cookie 下的登录及设备授权。

## 日常检查与变更

```bash
systemctl status php-fpm-81.service ysumc-janus.service --no-pager
docker compose --env-file deploy/.env -f deploy/compose.yaml ps
curl -f -H 'Host: skin.ysumc.net' http://127.0.0.1:13011/api/launcher/manifest
journalctl -u ysumc-janus.service -n 100 --no-pager
```

应用日志位于 `storage/logs/`。日志可能包含邮件调试内容、验证码或授权信息，不要整体公开。

### 插件安装目录权限

后台安装、更新和删除插件会写入部署根目录的 `plugins/`，`public/plugins/` 只存放公开插件资源。宝塔 PHP 工作进程以 `www` 身份运行，两处目录及需要更新的插件文件均须允许该用户写入。

首次部署或通过 root 解压插件后，检查插件文件属主；需要时修正：

```bash
cd /www/wwwroot/ysumcusercentre
chown -R www:www plugins
chmod -R u+rwX plugins
```

验收时应通过实际 PHP-FPM 检查 ZIP 解压及覆盖写入，普通 root shell 下可写不能证明 PHP 可写。当前宝塔部署不使用 `ReadWritePaths`；若手动启用备用的独立 PHP 服务，其 `ProtectSystem=strict` 仍需配套目录写入白名单。

2026-10-21 已修复线上插件上传时报 `ZipArchive::extractTo(.../plugins/yggdrasil-connect/LICENSE): Read-only file system`：为 `plugins/` 增加 `ReadWritePaths`，将该根目录从 `root:root` 改为 `www:www`（保留 755），并同步服务器的部署服务文件。已有插件内容的权限未改动。配置及目录原属主、模式备份在 `.deploy/backups/20261021T054427Z-plugin-permissions/`。PHP 服务重启后状态正常，已在其挂载命名空间内以 `www` 验证临时目录创建、ZIP 解压、文件覆盖、清理及现有插件路径可写；HTTPS 登录页与启动器 manifest 均返回 200。此次未重新上传业务插件。

更新前备份数据库、`storage`、环境文件和 OAuth 私钥；数据库转储应保存在 root 专属目录，不能放在 `public`。先验证备份能读、配置校验通过，再更新新站服务。

回滚代码前检查数据库迁移兼容性；不能通过直接降低启动器实例 revision 回滚已发布实例。不要在已有数据库上运行 `migrate:fresh` 或删库重装。

已生成一次初始备份：`.deploy/backups/20261020T171955Z/`，包含 `database.sql.gz` 和 `site-state.tar.gz`。已检查转储包含建表语句、归档包含环境配置与 OAuth 私钥；尚未演练恢复。目录权限为 700，备份文件为 600。该备份不能代替后续定期备份。

## 2026-10-21 验收记录

| 项目 | 结果 |
| --- | --- |
| 两台权威 DNS、Cloudflare DNS、Google DNS 的 A 记录 | `103.39.66.86` |
| 权威 DNS 的 AAAA 记录 | 无记录 |
| Nginx 配置与 reload | 通过 |
| 新源站 HTTPS 证书链与域名匹配 | 通过 |
| 登录页、注册页及本地 JS/CSS | 通过 |
| HTTPS 密码登录、Secure 会话、用户页 | 通过 |
| 中文语言选择 | `zh_CN.js` |
| 启动器 manifest / announcements / Yggdrasil 元数据 | HTTP 200 |
| 无 Bearer Token 的皮肤 API | HTTP 401 |
| OIDC discovery 中 issuer 和各 endpoint | 全部使用正式 HTTPS 域名 |
| OAuth 设备码申请 | 成功 |
| 未确认设备码轮询 | `authorization_pending`，符合预期 |
| 设备确认 → OAuth 同意 → 令牌领取 | HTTP 流程通过，测试范围为 `openid offline_access` |
| 刷新令牌、Bearer 用户信息查询 | 通过 |
| 撤销后用户信息查询 / 刷新 | HTTP 401 / HTTP 400 `invalid_grant` |
| 旧刷新令牌再次使用 | HTTP 400 `invalid_grant` |
| Janus 构建与自动化测试 | 13 项通过 |
| 本域名证书续期演练及 reload hook | 通过 |
| 浏览器实际渲染、游戏角色选择及真实进服 | 待验收 |

初次 HTTPS 验证期间，服务器 DNS 缓存仍返回旧站；为避免误判，使用新 IP 建立连接，同时保留 `skin.ysumc.net` 的 SNI、Host 和系统证书链校验。没有关闭 TLS 校验。

缓存随后已更新，后续授权、令牌刷新和撤销验证使用正常域名解析完成。所有测试访问令牌已撤销。本次没有创建测试游戏角色或修改现有 Minecraft 服务器。

本地进程启动工具暂时返回 Windows“拒绝访问”，因此未完成真实浏览器渲染验收；HTTP 表单流程和资源检查不能代替视觉验收。Janus 构建及测试在服务器完成。

## 尚需业务方确定的上线内容

- SMTP 地址、端口、发件地址和凭据：用于账号验证及找回密码。
- 具体 Minecraft 服务端或代理实例：本次没有修改现有游戏服务器的认证方式。
- 官方整合包、版本、Java 要求及下载文件：目前 manifest 中官方实例列表为空。
- 启动器正式发行版本、签名密钥及更新文件：开发版运行不等于更新服务已经上线。
- 完整联调：账号登录、创建角色、设备授权、皮肤上传、加入指定游戏服。

启动器仍保留下载与发现、普通实例、微软账号、离线账号和外置登录；AI 功能关闭。社团审核类业务不属于此次部署范围。
> 2026-10-21 界面更新已部署：参见 [界面调整与验证记录](UI-20261021.md)。已完成浏览器实测，早期记录中“视觉检查待完成”的状态由该记录更新。Blessing Skin 声明和链接继续保留。

> 2026-10-21 旧站数据及纹理已迁入新站：参见 [数据迁移记录](MIGRATION-20261021.md)，包含数量、校验结果、保留的账号状态与回滚位置。

