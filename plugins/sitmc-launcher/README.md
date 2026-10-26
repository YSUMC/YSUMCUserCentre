# YSUMC 启动器服务插件

## mc-interop v1（1.3.2）

本分支基于 `learning/sitmc-launcher` 的 SIT 1.3.0，此前同步到上游提交 `7fcf275`；2026-10-23 已审查 `cf207bb`，增补其中的互采修复及主清单拆分，按 [mc-interop v1 协议](launcher-interop-protocol-v1.md) 实现。已保留 YSUMC 的用户信息地址、更新接口、角色所有权和皮肤上传安全修复。

1.3.2 同步友站实例 ID 命名空间：本站 `survival` 不变，友站实例使用 `sitmc:survival`，不同站点的同名实例可以同时展示。旧客户端兼容投影与 `/api/launcher/friends` 使用相同友站 ID；同一来源的重复 ID 保留第一条并记录诊断。带 `source` 的转引条目不再二次采入，避免回环；本站实例和站点 ID 仍不允许冒号。此更新在独立分支 `codex/sit-launcher-sync-20261022` 完成，按用户要求未运行回归测试，等待用户核验。

| 端点 | 行为 |
| --- | --- |
| `GET /.well-known/mc-interop-v1.json` | 标准匿名出口：`site / packs / peers / updatedAt`，packs 只含本站 |
| `GET /api/launcher/interop/v1/site` | 同一份文档的反向代理入口 |
| `GET /api/launcher/friends` | 独立友站目录，YSUMC 新客户端与 SIT 按来源分组读取 |
| `POST /api/launcher/interop/v1/refresh` | 后台同步；必须使用 `X-Interop-Token` 请求头，不接受 URL token |
| `GET /admin/launcher/interop` | 站点身份、发布选择、友站列表、诊断及定时同步命令 |

新部署默认不公开任何实例。管理员在「对外清单」勾选合法实例后才公开；原有记录直接作为规范投影的数据源，不会定时导入旧 schema:1 清单。站点名读取 Blessing Skin 的 `site_name`，站点 ID 默认 `ysumc`，首次保存后保持稳定。域名读取后台配置或 `APP_URL`，不信任请求的 Host。

`/api/launcher/manifest` 保留 schema:1，只导出已启用、元数据完整的 mrpack + SHA-1 条目，独立于“对外清单”的上架选择。本启动器发布后即可展示和下载；对外上架只影响公共互操作目录。下载接口允许本启动器已发布或公共清单已上架的当前 revision，停用实例或请求历史 revision 仍被拒绝。仅含服务器连接信息、其他包格式或只有 SHA-512 的条目由公共互操作目录提供。

纯服务器省略 `artifact/revision`；未填写端口时省略 `port`，不补 25565。每个条目仅绑定一个服务器。制品支持五种格式、SHA-1/SHA-512 至少一个；上传会计算两种摘要。修改包内容生成递增 revision，旧内容回滚也复制为新 revision。

上传支持 mrpack、MultiMC 元数据解析；server-zip / raw 使用后台手填版本。解析器只读取受限元数据，不解压、不修改包体，并拒绝危险路径、符号链接、超大条目和未知加载器。CurseForge 包中的平台文件 ID 必须先由发布方解析、取得再分发许可并制作可安装制品；本插件不含 CurseForge 文件解析/下载服务，未解析的标准 CF 导出会被拒绝，建议转换成 mrpack。登记外链时由管理员提供已核对的格式、版本、长度及摘要，本插件不下载大文件验证包体。

公开响应提供 `ETag`、`Cache-Control: public, max-age=300`，支持弱 ETag、列表和 `*` 条件请求；站点名、删除、撤回、友站 stale 状态变化都会更新顶层时间。共享缓存响应不附加访客语言 Cookie。建议目录小于 256 KiB，超过 1 MiB 返回明确的 503。

友站只取管理员允许的 v1 地址；固定校验后的公网 DNS、TLS 验证、禁用代理与重定向，15 秒超时、最多 3 次退避重试、1 MiB 响应限制。未知枚举条目跳过并进入诊断；失败或全部条目损坏保留最近成功快照并标记 stale，合法空目录则表示源站撤回发布。过期快照也标记 stale。不会递归采集友站的 peers；外来 ID 使用原始来源站点的命名空间，只采直连友站自己的条目，同一命名空间内重复的 ID 会被丢弃并记录诊断。

公开请求只读取快照，不在 PHP 请求结束时发起网络同步。可以在后台手动同步，或安装后台提供的 cron 命令；生产已配置每 5 分钟同步一次。同步口令仅放在请求头，定时配置文件需限制读取权限。

数据库迁移是增量的：新增互操作表、制品 format/sha512、将实例/友站 ID 列扩展到 128 字符。启用插件或首次读取仓库时执行。备份数据库和插件目录后再部署，回滚见 [本地验收与部署说明](../../deploy/php85/launcher-interop-v1.md)。

新包还保存每个 revision 的游戏与加载器版本，回滚时一并恢复。历史包没有这份版本快照时，后台拒绝直接回滚，请重新上传或登记旧包并确认版本；不能从当前实例的版本猜测旧包要求。

1.3.1 已随 PHP 8.5.12 上线；1.3.2 的其他源码同步项仍按各自部署记录处理。本启动器发布与公共上架的分离修复已同步到线上，未改变公共清单的现有选择；新客户端合并本站 manifest 与互操作目录。

基于 SIT-Minecraft 的 `sitmc-launcher` 插件适配 YSUMC，本分支运行于 PHP 8.5.12 / Laravel 13 的 Blessing Skin 6.x 适配版。提供官方实例清单、整合包、公告、启动器更新元数据和受授权保护的皮肤接口，以及相应的管理后台。

为兼容既有路由和数据，内部插件 ID、PHP 命名空间与表名仍保留 `sitmc-launcher` / `sitmc_launcher_*`。本插件不是独立服务；需要先补齐并部署用户中心。整体准备和已知缺口见 [YSUMC 系统复用开发文档](../../../docs/YSUMC系统复用开发文档.md)。

## SIT 上游增补（2026-10-23）

审查范围：`7fcf275..cf207bb`。已增补 `e5037e5`（禁止添加本站域名，包括 APP_URL 回退域名）、`e2da316`（well-known 返回 403/404 时请求同源 `/api/launcher/interop/v1/site`）、`f8a0bd8`（跳过带 source 的转引条目）。备用请求继续执行本地已有的公网 DNS 固定、TLS 校验、禁用重定向、超时和大小限制。

已同步 `cf207bb` 的“SiteDocument 只含本站”行为。YSUMC 新客户端读取本站 manifest、own-only 主清单和独立 friends；friends 的 SHA-512 字段已补齐。旧 `ysumc/launcher/<version> (<os>)` 且未发送 `X-YSUMC-Catalog: split-v1` 的请求仍获取原聚合投影；标准采集请求获取本站清单。`Vary: User-Agent, X-YSUMC-Catalog` 隔离共享缓存，两个投影各自维护稳定时间戳。MUTMCL 仍只使用自己的 manifest。

## 安装

1. 把整个 `sitmc-launcher` 目录放到站点的 `plugins/` 下(默认 `base_path('plugins')`,可用 `.env` 的 `PLUGINS_DIR` 改)。
2. 进后台 **插件管理**(`/admin/plugins/manage`)启用它;或在服务器上执行 `php artisan plugin:enable sitmc-launcher`。
3. 无需跑迁移:插件会在启用时(以及首次请求时兜底)增量升级自己的九张表。

后台从 `/admin/launcher` 进入，通过页签切换实例、公告、版本和对外清单。

添加友站支持完整的 HTTPS 清单地址（包括反向代理路径前缀），也兼容根域名；按清单地址去重，离线站点可先保存并在同步成功后识别站点身份。

## 客户端怎么指向它

当前 YSUMC 启动器固定使用 `https://skin.ysumc.net`。前端入口在 `apps/app-frontend/src/config.ts`，Rust 服务地址与清单来源限制在 `packages/app-lib/src/ysumc.rs`。换域名需同步改动两端并重新构建，不能只改一个前端变量。

PHP 配置 `APP_URL=https://skin.ysumc.net`、`YSUMC_USERINFO_URL=https://skin.ysumc.net/api/janus/userinfo`。设备授权和皮肤写入还需要 Yggdrasil Connect、Janus、共享数据库/OAuth 密钥及公开客户端；安装本插件本身不构成完整认证部署。

接口契约见 [启动器互通协议](../../../Axolotl-YSUMCL/docs/launcher-protocol.md)。本插件实现的端点：

| 端点 | 说明 |
| --- | --- |
| `GET /api/launcher/manifest` | 清单 JSON。带 `ETag`,客户端回传 `If-None-Match` 时返回 `304` |
| `GET /api/launcher/packs/{instanceId}/{revision}` | 下载某个版本的整合包(登记了外链的版本会 302 到外链) |
| `GET /api/launcher/announcements` | 公告**裸 JSON 数组**(不带外层对象)。只返回启用、规则合法且处于生效时间内的公告,超过 200 条时按优先级与发布时间截断 |
| `GET /api/launcher/update/latest?channel=release\|beta` | 最新版本元数据 `{version, notes, pub_date}`。通道缺失或未知一律按 `release`;该通道没有版本时返回 `204` |
| `GET /api/launcher/update/{target}/{arch}/{current_version}` | Tauri v2 **动态更新器**文档 `{version, notes, pub_date, url, signature}`;没有比 `current_version` 更新的版本时返回 `204` |
| `GET/POST/DELETE /api/launcher/skins` | 查询衣柜、上传 PNG、移除材质；需 Bearer token 和角色所有权核验 |
| `PUT /api/launcher/skins/equip` | 更新皮肤/披风穿戴；需 Bearer token 和角色所有权核验 |

清单、公告与更新元数据都是公开的(启动器不带凭据),但**不包含任何玩家数据**,只描述有哪些实例、什么版本、从哪里下载。

## 公告怎么下发

插件返回公告裸数组；当前 YSUMC 客户端同时兼容其支持的包装格式。公告下发规则：

- 只有 `enabled` 为真、**所有字段都符合客户端规则**、且 `starts_at` 已到而 `ends_at` 未到的公告才会被下发;
- 规则在 `AnnouncementRules` 里与客户端逐条对齐(字段长度、枚举、时间可解析、按钮链接必须是 http(s) 且不带用户名密码),后台列表会直接写出某条公告**为什么不会下发**;发布时间已过但还未生效、或已经过期的公告会标为「未开始 / 已结束」;
- 文档最多 200 条,超出时按优先级(高→低)与发布时间(新→旧)截断——超过 200 条会让客户端**丢弃整份文档**;
- 时间在后台用 `datetime-local` 填写,按站点时区(`config('app.timezone')`)存储,下发给客户端时统一转成 UTC 的 ISO-8601(`2026-02-01T00:00:00Z`)。

## 自更新（Tauri 动态更新器）

`GET /api/launcher/update/{target}/{arch}/{current_version}` 返回 Tauri v2 动态更新器文档:

```json
{
  "version": "1.3.0",
  "notes": "### 新增\n- ...",
  "pub_date": "2026-02-01T00:00:00Z",
  "url": "https://downloads.example.invalid/launcher/1.3.0/ysumc-update.zip",
  "signature": "untrusted comment: signature from minisign secret key\nRWS...\n..."
}
```

选择规则:

1. 只考虑 `enabled` 为真、通道匹配、版本号与发布日期都合法的版本;
2. 显式传入通道时只选择该通道；没有通道时优先 `release`，该平台没有可用 `release` 才考虑 `beta`。请求可通过查询参数 `channel` 或 `X-YSUMC-Channel` 头传入通道，兼容旧 `X-Axolotl-Channel` 头；
3. 版本可以用「目标平台/架构」限定:**两项都留空表示所有平台**,填写后只对匹配的请求生效,取其中发布日期最新的一个;
4. 动态更新器需要**制品地址与 minisign 签名同时存在**,缺任一则该版本不参与选择(但仍可以只用来提示版本);
5. 配置的版本**不比** `current_version` 新时返回 `204 No Content`;版本号按 `.` 与 `-` 分段做数字比较(`1.10.0` > `1.9.0`,`1.3.0` > `1.3.0-beta.1`);**任意一端无法解析时直接返回文档**——宁可多发一次更新,也不能把玩家锁在旧版本上。

当前 YSUMC 客户端已对接本站更新服务。发行构建必须配置 `YSUMC_UPDATER_PUBLIC_KEY`，与制品签名密钥配对；未配置公钥时不执行在线更新。示例中的制品地址和签名仅说明字段，不能用作实际发布。

Linux apt 更新另需 `/api/launcher/update/versions` 和 `.deb` 的 SHA-256、大小、平台信息，当前插件尚未实现该目录。Windows/macOS 也需真实签名制品升级验收后才能认定可用。

## 后台怎么用

1. **新建实例**:实例标识只能用小写字母/数字/`_`/`-`(与启动器一致,最长 64 字符),创建后不可改。
2. **上传 `.mrpack`**：自动计算 SHA-1 与大小，并切换当前发布 revision。玩家在启动器点击安装或更新；客户端对绑定的官方实例执行版本检查，不会因发布新包而静默重装所有普通实例。
3. **登记外链整合包**：使用可访问的 HTTPS 地址，填写实际 40 位 SHA-1 和正字节大小；摘要或文件大小不符会导致校验失败。
4. **恢复旧内容**：重新上传/登记旧包为更高 revision。“激活旧版本”会复制旧内容为递增 revision；不允许直接删除当前版本。
5. **启动器更新策略**：`min_version`、`latest_version`、`force_update` 与官方实例启动检查配合使用；不把整个应用、下载页和普通实例作为阻断范围。
6. **公告**(`/admin/launcher/announcements`):正文与摘要都按 Markdown 填写;列表里的「下发状态」会写明这条公告会不会被客户端接受、是否还没到开始时间或已经结束。
7. **版本**(`/admin/launcher/releases`):一个版本可以先只写版本号与更新说明(用于提示),等签名完成后再补上制品地址与 minisign 签名,那时动态更新器才会真的安装它。

本站 manifest 需要合法 mrpack 和 SHA-1，只受实例启用状态与包完整性控制；公共 v1 出口也支持纯服务器、其他格式和 SHA-512，并额外遵守“对外清单”中的上架选择。公开下载 URL 不是账号访问控制；需要限制下载权限时应另行设计鉴权。

## MUTMCL 独立配置

- 管理入口：`/admin/launcher/mutmc`，需皮肤站管理员权限；也可从后台菜单「MUTMCL 启动器」进入。
- 公告管理：`/admin/launcher/mutmc/announcements`；版本管理：`/admin/launcher/mutmc/releases`。全部新增、编辑、删除、整合包操作都使用 MUTMCL 子路由，服务器固定品牌归属并拒绝跨品牌操作，不依赖表单下拉选择。
- 公告接口：`/api/launcher/mutmc/announcements`；版本接口：`/api/launcher/mutmc/update/{latest,versions,releases}`，自动更新：`/api/launcher/mutmc/update/{target}/{arch}/{current_version}`。未发布时返回空列表或 204，不回退到 YSUMC。
- 客户端清单：`/api/launcher/mutmc/manifest`。上传包的下载路由为 `/api/launcher/mutmc/packs/{instanceId}/{revision}`。
- 在 MUTMCL 页面新建实例，填写游戏版本、加载器、服务器地址、开服日期等信息，再上传 `.mrpack`，或登记 HTTPS 外链、SHA-1 和字节大小。发布且包信息完整后即可出现在 MUTMCL 启动器，无需勾选 YSUMC 的「对外清单」上架。
- 实例所属启动器创建后固定。实例 ID 在全站唯一，建议 MUTMCL 使用 `mut-` 前缀。MUTMCL 和 YSUMC 的实例清单、云地图及启动器版本策略分开存储，已有记录默认为 YSUMC。
- 未配置整合包时返回空清单。此管理入口不自动创建整合包，也不自动发布客户端安装包。

## 存储与限制

- 上传的整合包保存在 `storage/app/launcher-packs/`,只通过上面的下载路由对外提供,**不放在 web 根目录**。
- 单个整合包大小受 PHP 限制:后台会直接显示当前的 `upload_max_filesize` / `post_max_size`。整合包通常很大,建议把这两个值调到 512M 以上,或者改用外链。
- 文件按 `实例-版本-完整 SHA-1.mrpack` 命名,便于人工核对。

## 卸载

在后台删除插件会**删除插件的十一张表以及 storage 里已上传的整合包文件**。只想停用而不丢数据时,用「停用」而不是「删除」。

## 表结构

| 表 | 用途 |
| --- | --- |
| `sitmc_launcher_instances` | 实例(标识、名称、描述、图标、排序、当前版本、游戏/加载器版本、服务器地址、是否必需/启用) |
| `sitmc_launcher_packs` | 每个实例的每个版本(上传文件路径或外链、原始文件名、SHA-1、大小),`(instance_id, revision)` 唯一 |
| `sitmc_launcher_settings` | 启动器更新策略(min/latest/force_update) |
| `sitmc_launcher_announcements` | 公告(标识、标题、摘要、Markdown 正文、展示方式、优先级、开始/结束/发布时间、按钮链接与文案、是否启用) |
| `sitmc_launcher_releases` | 启动器版本(通道、版本号、Markdown 更新说明、发布日期、制品地址、minisign 签名、可选的 target/arch、是否启用) |
| `sitmc_launcher_memberships` | 用户等级、单个部门、最近修改人和时间 |
| `sitmc_launcher_membership_audit` | 用户分级变更前后内容、操作者和时间 |

表名不加前缀,但都通过 Laravel 的 `Schema`/`DB` 门面访问,所以在 `.env` 里配了 `DB_PREFIX` 也会正常工作。


## 用户分级与定向下发（YSUMC）

直接在后台“用户管理”（`/admin/users`）为用户设置普通用户、社员、部长、社长、管理员或超级管理员。插件会在表格与卡片两种视图中显示等级、部门标签，通过原权限铅笔按钮（卡片模式为权限菜单）打开统一编辑框，不再额外显示“调整等级和部门”入口。编辑框保留原有封禁操作入口；已封禁账号仍走核心解禁流程。原有搜索、分页及其他账号操作保持不变。原 `/admin/launcher/users` 地址跳转至用户管理，不再提供独立分级页面。社员、部长必须且只能选一个部门：宣传、运维、技术、建筑。普通用户至社长不取得后台管理权；已有管理员、超级管理员仍以皮肤站的核心权限为准。普通管理员只能管理普通用户至社长，超级管理员才能调整管理权限；禁止修改自己的管理权限，变更写入独立审计表。

页面增强全部通过插件的脚本、样式钩子实现，不修改核心页面或前端包，停用插件即恢复原界面。手动部署源码时，需同时将 `assets/user-memberships.js` 和 `assets/user-memberships.css` 复制至 `public/plugins/sitmc-launcher/assets/`，并清理路由、视图及 Twig 缓存。新安装或重新启用插件时，由皮肤站自动发布这些资源。数据接口 `GET /admin/launcher/users/data?uids[]=1` 只允许已登录管理员按当前页面 UID 列表读取；保存复用 `PUT /admin/launcher/users/{uid}`，使用网站会话和 CSRF 保护，权限仍在服务端检查。

实例、公告、版本编辑页均提供“公开”或指定接收等级。指定等级采用精确白名单，不自动包含更高等级；未登录请求只能获得公开内容。旧记录默认公开，旧管理客户端遗漏新字段时不会清除已有的限制。

客户端通过 `Authorization: Bearer <当前 Yggdrasil Connect / Janus access token>` 请求 `/api/launcher/identity`、`manifest`、`announcements` 和 `update/*`。服务端调用既有 userinfo 验证令牌，并核对 subject UID 和角色的当前归属，再从数据库读取权限；客户端提交的等级不参与判断。身份响应包含 `role`、`role_label`、单个 `department`、`department_label`、`tags` 和 `player`，不返回邮箱或令牌。身份和受众清单禁止共享缓存。

`manifest.catalog` 是按当前账号筛选的本站 mc-interop 结构，包含纯服务器实例；公开 well-known、友站和旧版聚合接口均不导出受限实例。友站自己的内容仍由友站负责，不能使用本站等级代替友站身份。受限整合包通过本站下载端点再次鉴权；外部 CDN 或已公开的安装包链接仍受其原有存储权限控制，本功能不撤回已下载的文件，也不替代游戏服务器白名单。

新版 YSUMC 启动器会在当前角色下显示等级与部门标签，并在切换账号时清除旧公告、更新和实例显示。令牌仅发送到固定本站 API，不转发给友站或下载 CDN。旧版启动器继续读取公开内容；首次发布支持分级的新客户端时应将该版本设为公开，待用户升级后再启用定向下发。

数据库升级为新增三类内容的 `audience_roles` 列、用户分级表和审计表，不批量改写现有用户权限。发布前备份数据库和插件目录；回退程序时应先停用受限内容或停用插件，因为旧插件不理解接收组限制，直接回退会重新公开这些内容。新表和列可保留，避免丢失设置与审计记录。
