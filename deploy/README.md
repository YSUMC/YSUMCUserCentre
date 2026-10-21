# YSUMC 用户中心部署记录与操作说明

本目录记录本次独立部署，作为根目录 `docs/YSUMC系统复用开发文档.md` 的部署补充。服务器实际目录以此处为准：`/data/program/ysumcusercentre`，不是早期方案中的 `/data/ysumc`。

## 范围与当前状态

- 主机：`root@103.39.66.86`；域名：`skin.ysumc.net`。
- 用户确认旧站已弃用，本次建立新数据库，不迁移旧站账号。
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
| Laravel / Blessing Skin | `storage/runtime/php-fpm.sock` | `ysumc-php.service` |
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

新站通过独立 PHP-FPM 的 `open_basedir` 和写入目录配置访问 `/data`；没有放宽全局 PHP 配置。运行时使用服务器现有 PHP 8.1。正式升级 PHP 前需验证此版本 Blessing Skin、Laravel 和插件的兼容性。

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
cd /data/program/ysumcusercentre
docker compose --env-file deploy/.env -f deploy/compose.yaml up -d --wait
php -d open_basedir=/data/program/ysumcusercentre:/tmp:/proc deploy/bootstrap-site.php
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
  -w /data/program/ysumcusercentre/public \
  -d skin.ysumc.net --cert-name skin.ysumc.net --non-interactive
```

4. 证书存在后，将本目录 `nginx-domain.conf` 安装到 `/www/server/panel/vhost/nginx/ysumc-usercentre-domain.conf`。先备份本次维护配置，再执行 `nginx -t`，成功后才 reload。
5. 不覆盖服务器上名为 `skin.ysumc.net.conf` 的旧配置：该文件实际服务其他域名。
6. 将 `certbot-deploy-hook.sh` 安装到 `/etc/letsencrypt/renewal-hooks/deploy/ysumc-usercentre.sh`，权限为 755。该 hook 只处理本域名证书，先校验 Nginx 再 reload。验证 `certbot.timer` 与本域名续期演练。

应使用 HTTPS 检查 OIDC discovery 的 issuer、authorization/token/device endpoints；这些地址全部应指向 `https://skin.ysumc.net/api/janus`。同时验证 Secure Cookie 下的登录及设备授权。

## 日常检查与变更

```bash
systemctl status ysumc-php.service ysumc-janus.service --no-pager
docker compose --env-file deploy/.env -f deploy/compose.yaml ps
curl -f -H 'Host: skin.ysumc.net' http://127.0.0.1:13011/api/launcher/manifest
journalctl -u ysumc-janus.service -n 100 --no-pager
```

应用日志位于 `storage/logs/`。日志可能包含邮件调试内容、验证码或授权信息，不要整体公开。

### 插件安装目录权限

后台安装、更新和删除插件会写入部署根目录的 `plugins/`，`public/plugins/` 只存放公开插件资源。`ysumc-php.service` 使用 `ProtectSystem=strict`，必须将这两个目录都列入 `ReadWritePaths`；否则即使文件属于 `www`，解压仍会报 `Read-only file system`。保留该隔离设置，只放行需要写入的目录。

首次部署时，确保 `plugins/` 及其中的插件文件归 PHP-FPM 用户 `www:www` 所有，并允许属主读写、遍历目录。已有部署修改服务配置前先备份；应用更新后的服务文件：

```bash
cd /data/program/ysumcusercentre
chown -R www:www plugins
chmod -R u+rwX plugins
install -m 644 deploy/ysumc-php.service /etc/systemd/system/ysumc-php.service
systemd-analyze verify /etc/systemd/system/ysumc-php.service
systemctl daemon-reload
systemctl restart ysumc-php.service
systemctl is-active ysumc-php.service
systemctl show ysumc-php.service -p ProtectSystem -p ReadWritePaths
```

验收时应在 PHP 服务的挂载命名空间内以 `www` 身份检查写入及 ZIP 解压；普通 root shell 下可写不能证明 PHP 可写。新增插件和更新已有插件都需要验证。

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

