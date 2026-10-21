# PHP 8.5.12 生产切换记录

2026-10-22 02:35:50（北京时间）已恢复 `https://skin.ysumc.net` 公网访问。此次切换根据用户新授权执行，替代此前“生产保持停站、不切换 PHP”的阶段限制。修改与验收记录保存在 `codex/php85-compat` 独立分支。

## 开站后登录页面修复

用户随后报告登录页只有“登录以管理您的角色与皮肤”，没有账号/密码输入框。真实浏览器复现了 `Cannot read properties of null (reading 'base_url')`：旧 `config/twigbridge.php` 没有启用新版 TwigBridge 的 `TwigBridge\Extension\Laravel\Event` 扩展，嵌套和父模板的 Laravel view composer 未执行，使 `blessing-globals` 成为 `null`，页面标题、翻译脚本和 eduroam 入口也缺失。原 98 项生产 HTTP 检查直接提交登录接口，未执行浏览器 JavaScript，漏掉了该缺陷；不能将此前接口成功等同于浏览器登录成功。

现已启用官方包自带的事件扩展，移除失效的旧 `base_template_class` 配置。新增两个实际渲染回归测试，修复前均稳定失败，修复后完整隔离测试为 **935 项、2021 条断言通过**。生产配置已备份到私有备份目录的 `login-view-composer-fix/`，按 www 用户重建配置/模板缓存并平滑重载 PHP FPM。

修复后使用临时普通账号在真实浏览器填写表单、点击登录，成功进入生产用户中心；角色管理页及刷新后的登录会话正常，没有新增浏览器错误。测试账号已精确删除。HTTP 冒烟脚本及开站前检查已补上前端配置、翻译脚本和插件入口断言，后续不能仅凭 HTTP 200 放行。

排查期间用户正在升级 Nginx，Web 监听曾暂时消失；这与原先页面表单不渲染是两个问题。本修复没有修改 Nginx 配置或中断升级，生产监听恢复后完成了上述浏览器验收。相关证据见 `evidence/login-view-composer-20261022/`。

## 当前部署

| 项目 | 实际状态 |
| --- | --- |
| PHP 发布目录 | `/www/wwwroot/ysumcusercentre-php85-20261022` |
| Nginx 文档根目录 | 上述目录的 `public/` |
| PHP 运行时 | `/opt/ysumc/php-8.5.12`；实际 FPM 为 8.5.12 |
| PHP 服务 | `ysumc-php85-fpm.service`，active、开机启动 |
| PHP 配置 | `/opt/ysumc/php-8.5.12/etc/php.ini` 与 `php-fpm.conf` |
| PHP socket | `/run/ysumc-php85/php-fpm.sock`，仅本机 Unix socket |
| Janus | 原生产目录、原进程继续运行，Node 26.12.1 |
| 旧 PHP 8.1 | 保留且 inactive |
| 宝塔站点 | id 51，目录更新为新发布目录，状态恢复为开启 |

PHP 8.5 使用独立 systemd FPM 服务管理，未伪装成宝塔软件商店安装的 PHP 套件。维护时使用上述服务和配置；宝塔中重新选择 PHP 8.1 会覆盖当前站点处理器。CLI 的编译默认配置目录仍是原构建目录，运维命令必须明确传入 `-c /opt/ysumc/php-8.5.12/etc/php.ini`，与生产 FPM 的启动参数一致。

旧目录 `/www/wwwroot/ysumcusercentre` 保留完整，继续承载 Janus、数据库容器的 `.runtime/mysql` 挂载和共享 `storage/`。新目录的 `storage` 链接到旧目录的同名目录；新版本的 `vendor/`、插件代码和 `bootstrap/cache/` 独立。不要删除旧目录，也不要将候选环境 `ysumcusercentre-next` 的 `.env`、密钥或数据库覆盖到生产。

生产 `.env` 按原样复制；APP_KEY、OAuth/Janus 私钥、Yggdrasil 密钥和 UUID 均保留。未执行数据库 schema migration，未更换生产数据库、OAuth 客户端编号或已有客户端 secret。个人访问客户端仍为 1，Janus 共享客户端仍为 2。

发布使用已测试的 192 个迁移文件，逐一校验与 `16a18ccb9f4ec11b9b39a91ccb1350419d50bf7a` 的发布清单一致；后续 Node 修复与运维文档提交不改变这些应用文件。依赖按锁文件执行 `composer install --no-dev --prefer-dist --optimize-autoloader`，没有忽略平台限制。

## 备份与回滚

私有备份目录（root 权限，位于 Web 根目录外）：

```text
/www/server/php-compat-assessments/20261022-migration/production-cutover-20261022
```

- `production-files.tar.gz`：源码、原依赖、配置、密钥、上传数据等，330,705,532 字节。排除运行中的 `.runtime/mysql` 和 Git 元数据；数据库单独逻辑备份。
- `database.sql.gz`：完整生产 SQL 备份，205,160 字节。已在同版本 MariaDB 的隔离库实际恢复，32 张表的行数全部一致，演练库随后删除。
- `nginx-stopped.conf`、`php81-etc/`、Janus unit/drop-in、`baota-site.db`、`baota-site-before.json`：切换前配置和元数据。
- `SHA256SUMS`：源码备份、数据库备份和原 Nginx 配置校验值。

已实际执行并通过回滚演练。需要立即退回切换前的停站状态时，以 root 执行：

```sh
bash /www/server/php-compat-assessments/20261022-migration/production-cutover-20261022/rollback.sh
```

脚本恢复原停站 Nginx 配置与宝塔目录/状态，验证配置后 reload Nginx，并停止、取消自启动新 FPM。Janus 保持运行；旧 PHP 8.1、旧代码继续保留。此操作恢复的是切换前的**停站状态**，不会自动开放旧 PHP 8.1，也不会覆盖数据库或上传文件。恢复旧数据库快照只应作为另行评估的数据灾难恢复步骤，否则会丢失重新开站后的新增数据。

备份 SHA-256：

```text
634553804f53cdcdf46d87c1a3948dee303483a6a02bdda75cd5161db54f1b34  production-files.tar.gz
c0810bf57914c46d6a5bb436571ac9073309574230a8913d66bf43d3b013fcf3  database.sql.gz
```

## 生产验收

先将公网保留在停站页，仅允许目标地址为 `127.0.0.1` 的 TCP 连接进入新站点。验证使用正式 HTTPS 主机名、生产数据库和生产 Janus，测试客户端的 DNS 固定到回环地址。请求头无法绕过此开站前的入口限制。

98 项真实 HTTP 检查通过：

- 密码登录、CSRF 拒绝、会话保持、普通用户/管理员权限边界。
- Janus 设备码等待与批准、PHP OAuth 同意 nonce、选角、令牌发放、刷新、PHP 跨服务撤销及刷新拒绝。
- Yggdrasil 密码登录、个人访问令牌、刷新、join/hasJoined、生产公钥签名验证和撤销。
- 私有皮肤上传到共享生产存储、GD 预览与头像、通过正常 API 删除测试材质。
- 普通用户禁止上传插件 ZIP，管理员实际解压 ZIP 并核对 LICENSE 文件内容。
- eduroam 登录表单、启动器公开接口和管理页面、禁用注册插件的既有 503 提示。

私有验证修复了新目录继承旧 `open_basedir` 路径的问题；新 `.user.ini` 允许新发布目录和原共享 storage，保留原上传上限。插件目录列入 FPM 可写路径，实际解压没有再出现只读文件系统错误。

直接通过生产 FPM socket 探测，确认 PHP 8.5.12、49 个扩展、正确 ini 路径以及 `session.upload_progress.enabled=0`。Composer 平台要求、无开发依赖安装、配置/视图缓存通过；审计为 0 个安全公告、0 个停止维护依赖。

测试采用临时合成账号、角色、OAuth 客户端和 ZIP；测试结束后已移除相关数据及 Janus 测试授权记录。核对原有 users、players、uuid、oauth_clients、options 和 migrations 的行数及内容摘要完全一致，生产 `.env` 与签名密钥文件摘要不变。

开站后又通过 9 项公网检查、5 个同源 JS/CSS 资源检查，并从服务器外部 Windows 客户端确认登录页 HTTP 200 和正确 Janus issuer。PHP 服务无自动重启；Janus 的累计 NRestarts=252 为此前 Node 路径故障遗留，本次进程及计数未变化。

未使用真人校园账号或启动真人 Minecraft 客户端。eduroam 的真实上游账号可用性不属于本次通过的项目；已验证的是插件页面、此前隔离环境的模拟认证回归，以及实际生产的本站登录/授权链路。

完整脱敏证据见 [evidence/production-20261022](evidence/production-20261022)。含数据库、密码或密钥的备份和测试凭据留在服务器私有目录，未加入 Git。

## 运维文件

`production-stage.py`、`production-private-cutover.py`、`production-fixture.php`、`production-http-smoke.py`、`production-runtime-check.py` 和 `production-open.py` 记录本次执行步骤。它们绑定此次服务器、日期和发布路径，并带有备份/目标存在性检查；现有生产环境不要重跑初始化。复用到其他发布时须调整成套路径、备份名和应用/数据库断言，并先在隔离环境验收。
