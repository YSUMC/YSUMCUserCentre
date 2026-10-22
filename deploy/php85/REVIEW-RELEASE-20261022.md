# 审查、合并与生产发布（2026-10-22）

本次按用户明确授权完成部署并恢复 `https://skin.ysumc.net` 访问，替代此前停站限制。实际开发分支为 `YSUMC-dev`。首次发布的应用版本为 `8d1f3695136705a0e3b82381d87013e09ca33612`；后续功能和部署变更见下节。

## 当前生产状态：插件兼容与目录归并

2026-10-22 后续部署应用提交 `d59449a`，基于此前 Janus 邮箱验证策略修复 `1c50557`，在独立分支 `codex/plugin-compat-eduroam-cleanup` 完成修改。

- 邮箱域名限制 `restricted-email-domains` 更新为 **0.3.1**。设置页改用服务端模板和原生表单，移除运行时对旧前端框架全局变量的依赖；补充域名格式校验、大小写归一化、重复项处理与空配置兼容。
- 实际安装的另一个限制插件为 **单角色限制** `single-player-limit`，更新为 **3.0.5**。角色绑定页面适配当前前端，并修复空用户处理；绑定时通过事务和正常删除事件清理其他角色。已有多个角色的用户须手动选择保留哪个角色，页面保留删除其他角色的提示；本次部署未替用户绑定或删除角色。
- EDUROAM 更新为 **0.1.3**。环境变量在 `config/auth-eduroam.php` 中加载，插件运行时通过 `config()` 读取，修复启用 Laravel 配置缓存后 `env()` 不可用的问题。认证域名为 `ysu.edu.cn`，存储域名为 `mail.ysu.edu.cn`；修复存储域名读取错变量的问题，并兼容旧认证域名下的已有账号。
- **唯一生产目录为 `/www/wwwroot/ysumcusercentre`**。Nginx、PHP-FPM、Janus、宝塔站点和本机入口均已对齐；`public/.user.ini` 的 `open_basedir` 也已修正为该目录。
- 原 `ysumcusercentre-release-20261022` 重命名为保留目录；旧原始目录及 `ysumcusercentre-next`、`ysumcusercentre-php85-20261022`、`ysumcusercentre-review-20261022` 已删除。Janus 内三份旧构建/依赖备份也已清理。
- `storage` 现为保留目录内的实体目录；`.runtime/mysql` 保留原数据。移动前后 inode 相同，MariaDB 容器实际挂载与保留目录一致。原 `.env`、Janus 配置与密钥校验未改变。
- PHP 继续使用 **8.5.12**，Janus 继续使用 Node **26.12.1**。两服务正常运行；每 5 分钟互通刷新任务仍恰好一条，未输出或改动同步 token。

按用户要求，本次**未运行回归测试，未尝试校园账号认证**。仅完成代码语法、部署文件一致性、服务启动和基本入口检查：登录页、EDUROAM 登录入口、Janus OIDC 元数据均返回 HTTP 200；两服务自动重启次数均为 0。脱敏证据见 `evidence/plugin-compat-consolidation-20261022/final-status.json`。后台配置保存、角色绑定和真实校园认证由用户核验。

本次私有备份位于 `/www/server/php-compat-assessments/20261022-consolidation`，不在站点根目录下。包含归并前五个目录的压缩包、SQL 逻辑备份、配置、宝塔元数据及校验清单；清理前已重新校验 SHA-256。目录归并与清理脚本分别为 `consolidate-usercentre.py`、`cleanup-usercentre.py`，绑定本次主机和目录，**不得当作通用发布脚本重复运行**。

回滚本次插件更新和目录归并：

```sh
bash /www/server/php-compat-assessments/20261022-consolidation/rollback.sh
```

该回滚从压缩备份恢复旧程序目录，保留当前数据库实体目录、上传文件和会话，恢复本次修改前的插件文件、`.user.ini`、服务和站点路径。它不会导入旧 SQL；回滚本身会重新产生旧版本目录，并短暂停止 PHP-FPM 和 Janus。此次按要求未实际执行回滚演练。下文的发布路径、验证数量和回滚记录均属于**首次发布历史**，不代表本次重新执行。

## 后续安装：CAS 与邀请码插件（仅安装，不启用）

根据用户提供的 `bs-plugin-auth-cas-main-0.2.2.zip` 和 `1760595431-invitation-codes.zip` 完成适配，在独立分支 `codex/cas-invitation-compat` 保存源码。

- CAS 安装目录为 `plugins/auth-cas`，本地适配版本 `0.2.3-ysumc.1`。增加 `config/auth-cas.php`，运行时读取缓存配置；命令行不启动 CAS 浏览器会话；补充退出/密码重置页面的 Web 会话中间件。默认验证 CAS 服务器证书，支持 `CAS_CA_BUNDLE` 指定受信任 CA。保留安装包自带的 phpCAS 1.6.1，并修正三处隐式可空参数、旧 boolean 转换和 switch 分号语法。
- 邀请码安装目录为 `plugins/invitation-codes`，本地适配版本 `3.1.3-ysumc.1`。输入框由服务端模板提供，使用现有注册请求钩子传递邀请码，不依赖 React 全局对象或 `ReactDOM.render`；后台编辑使用原生 fetch 和 CSRF。修复描述校验失败时的未定义变量、清理失败注册残留的邀请码状态，并保留其他注册限制插件的拒绝结果。
- **两个插件均未启用**。未填写 CAS 配置、未生成邀请码、未执行插件启用回调；邀请码数据表状态、注册策略、已有启用插件列表和 `.env` 均保持不变。站点原本关闭注册，`/auth/register` 保持 503“本站已关闭注册”；登录页和 Janus 元数据保持 200。
- 完成 84 个 PHP 文件语法及弃用语法检查、2 个 JavaScript 文件语法检查、安装文件哈希一致性检查。未跑回归或真实登录/注册测试，未声称完成 CAS 对接验证。

私有安装快照、适配包、原始包哈希、配置缓存备份和回滚脚本位于 `/www/server/php-compat-assessments/20261022-cas-invitation-install`。首次安装的入口检查将原有注册关闭响应误判为异常，已自动回滚；确认基线后重新安装完成，两个插件始终未启用。脱敏最终状态见 `evidence/cas-invitation-install-20261022/installed-status.json`。

需要撤回本次安装时，在两个插件仍未启用的前提下运行：

```sh
bash /www/server/php-compat-assessments/20261022-cas-invitation-install/rollback.sh
```

脚本将本次安装文件移回私有目录并重建配置缓存，不覆盖数据库，也不改变注册规则。

## 首次发布运行状态

- PHP 官网本次核查最新稳定版为 **8.5.12**（https://www.php.net/downloads.php）。服务器已有此前完整构建的同版本，核验后继续使用，无需重复编译或退回 PHP 8.1。
- 生产目录：`/www/wwwroot/ysumcusercentre-release-20261022`；共享存储仍在 `/www/wwwroot/ysumcusercentre/storage`。
- FPM：`ysumc-php85-fpm.service`，运行时 `/opt/ysumc/php-8.5.12`，Unix socket `/run/ysumc-php85/php-fpm.sock`，已开启自启动。
- Janus：`ysumc-janus.service`，Node `/www/server/nodejs/v26.12.1/bin/node`；新增 `30-release.conf` 指向新发布目录下的 `janus/`。原 `20-node-runtime.conf` 保留。
- 宝塔 `skin.ysumc.net` 目录和开启状态已同步。2026-10-22 17:14（北京时间）复核两服务均 active/running，本次进程自动重启次数均为 0。
- 直接通过 FPM socket 验证 PHP 8.5.12、49 个扩展及 `session.upload_progress.enabled=0`。原 `.env`、APP_KEY、OAuth/Janus 密钥和原有用户、角色、UUID 没有替换。

## 审查和补充修复

1. 插件数据库升级使用 MySQL/MariaDB advisory lock，避免两个 FPM worker 同时建表/扩列；隔离数据库两进程并发升级通过。
2. 后台发布勾选改为串行保存，连续操作不会被较旧请求覆盖；成功及失败重试路径均有回归测试。
3. 删除实例时事务清理互通发布标记和端点，复用实例 ID 的新对象不能继承旧公开权限。
4. 整合包流式响应补全 Symfony 8 要求的 `sendContent(): static` 签名；新增跨多个读取块的真实字节输出测试，并在 FPM 下实际上传、发布和下载验证。
5. 独立新发布目录的 `public/lang` 纳入明确的 FPM 可写目录，修复首次生成翻译脚本导致的登录 500。Janus 构建目录设置 `root:www` 和组读取/目录遍历权限，避免 root 构建时的 umask 使 www 无法启动入口文件。
6. 保留原工作目录未提交的 CurseForge 和版本目录合同测试，以及 PHP 8.1 修复包、宝塔迁移和 PHP 8.5 评估材料；原改动另存本地 `artifacts/premerge-20261022/` 和 Git stash，未丢弃。
7. 补充 Janus 官方 npm 审计，修复原有 10 项生产依赖公告：更新 `@nestjs/platform-express` 至 11.2.7、`multer` 至 2.4.0、`proxy-addr` 至 2.0.8。用 Nest 原生 provider 注入现有 Prisma client，移除不必要的 `nestjs-prisma` 及其旧 Angular 构建依赖；未降级 Prisma。生产只安装裁剪后的运行依赖。

PHP Composer 审计为 **0 安全公告、0 停止维护包**；Janus 生产依赖官方 npm 审计为 **0 公告**。没有使用强制跨主版本自动修复。

## 验证

| 验证 | 结果 |
| --- | --- |
| 独立 MariaDB/PHP 8.5 兼容测试 | 935 项、2021 断言 |
| launcher 互通/整合包回归 | 34 项、178 断言 |
| 安全回归 | 23 项、92 断言；既有图片库 18 条弃用提示仍存在 |
| Janus Node 26 全新锁文件安装、Prisma 生成、构建 | 通过；29 项测试通过 |
| 后台发布勾选并发测试 | 2 项通过 |
| launcher 合同检查 | 53 项通过，保留 CurseForge 与版本目录覆盖 |
| 生产真实 HTTPS/FPM/Janus 验收 | 143 项通过 |
| 从服务器外部的公网检查 | 14 项通过 |
| 运行代码一致性 | 755 文件与应用提交一致，UTF-8 文本仅规范化 CRLF/LF，二进制逐字节校验 |

生产验收包括密码和 CSRF、会话、普通用户/管理员隔离、设备码同意与选角、访问/刷新令牌、撤销、Yggdrasil join/hasJoined 与签名、原生及 launcher 皮肤上传/穿戴/删除、插件 ZIP 解压、互通清单双入口及 ETag、拒绝无凭据刷新、整合包未公开拒绝/公开下载/撤回拒绝、旧启动器 mrpack 兼容视图。临时账号、角色、客户端、令牌、材质、实例、整合包和测试插件目录均已清理。

浏览器保留的原有会话自动进入仪表盘，角色管理及新互通管理页面正常渲染；没有登出或修改用户真实账号。全新密码登录通过独立 HTTP 会话验证。未使用真人校园账号、邮件服务或真实 Minecraft 客户端进服，因此不声称完成这些外部系统验收。

互通协议采用显式上架：当前已有 `survival` 实例未勾选上架，公开清单为 0 条，友站为 0。没有擅自公开实例或配置友站。需要公开时在 `/admin/launcher/interop` 勾选；有友站后再配置定时同步。原版仅含平台文件 ID 的 CurseForge 导出仍需转换为可解析包，具体限制见插件文档。

## 备份与回滚

私有备份目录：

```text
/www/server/php-compat-assessments/20261022-review/backup
```

包含上次发布目录、原共享目录、完整 SQL 逻辑备份、Nginx/FPM/systemd 配置、宝塔站点元数据及 SHA256SUMS。SQL 在独立 MariaDB schema 实际恢复，原 32 张表行数一致；不是只检查压缩包可读。

恢复本次发布前的停站状态：

```sh
bash /www/server/php-compat-assessments/20261022-review/backup/rollback.sh
```

已实际演练：恢复停站 Nginx、停止并禁用新 FPM、恢复旧 Janus 工作目录及宝塔元数据。**回滚不覆盖数据库和共享上传数据，也不会开放旧 PHP 8.1。** launcher 新增表/扩列保持兼容旧代码；不要通过卸载插件回滚，也不要在有新用户活动后直接导入旧 SQL。

原版本代码和备份保留。发布步骤记录在 `release-20261022.py`，这是绑定本次主机与目录的分阶段脚本，已存在的目录不能直接重复初始化。隔离源码和最终补充修复的应用一致性以 `release-manifest.json` 为准。完整运行日志和凭据保留在服务器私有目录；脱敏结果见 `evidence/review-release-20261022/`。
