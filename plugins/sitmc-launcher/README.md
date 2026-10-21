# YSUMC 启动器服务插件

基于 SIT-Minecraft 的 `sitmc-launcher` 插件适配 YSUMC，运行于 Blessing Skin 6.x / Laravel 8。提供官方实例清单、整合包、公告、启动器更新元数据和受授权保护的皮肤接口，以及相应的管理后台。

为兼容既有路由和数据，内部插件 ID、PHP 命名空间与表名仍保留 `sitmc-launcher` / `sitmc_launcher_*`。本插件不是独立服务；需要先补齐并部署用户中心。整体准备和已知缺口见 [YSUMC 系统复用开发文档](../../../docs/YSUMC系统复用开发文档.md)。

## 安装

1. 把整个 `sitmc-launcher` 目录放到站点的 `plugins/` 下(默认 `base_path('plugins')`,可用 `.env` 的 `PLUGINS_DIR` 改)。
2. 进后台 **插件管理**(`/admin/plugins/manage`)启用它;或在服务器上执行 `php artisan plugin:enable sitmc-launcher`。
3. 无需跑迁移:插件会在启用时(以及首次请求时兜底)自动创建自己的五张表。

启用后后台侧栏会出现「**启动器实例**」「**启动器公告**」「**启动器版本**」三项,分别对应 `/admin/launcher`、`/admin/launcher/announcements`、`/admin/launcher/releases`。

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
4. **恢复旧内容**：重新上传/登记旧包为更高 revision。当前后台“激活旧版本”和删除当前包仍可能降低 revision，而客户端拒绝版本倒退；这一不一致尚待修复，正式发布前必须完成。
5. **启动器更新策略**：`min_version`、`latest_version`、`force_update` 与官方实例启动检查配合使用；不把整个应用、下载页和普通实例作为阻断范围。
6. **公告**(`/admin/launcher/announcements`):正文与摘要都按 Markdown 填写;列表里的「下发状态」会写明这条公告会不会被客户端接受、是否还没到开始时间或已经结束。
7. **版本**(`/admin/launcher/releases`):一个版本可以先只写版本号与更新说明(用于提示),等签名完成后再补上制品地址与 minisign 签名,那时动态更新器才会真的安装它。

只有状态为「已发布」且**有合法整合包**的实例才会下发给客户端；列表里的「发布状态」会写明缺什么（缺 SHA-1、字节大小、游戏版本、外链不是 HTTPS 等）。

## 存储与限制

- 上传的整合包保存在 `storage/app/launcher-packs/`,只通过上面的下载路由对外提供,**不放在 web 根目录**。
- 单个整合包大小受 PHP 限制:后台会直接显示当前的 `upload_max_filesize` / `post_max_size`。整合包通常很大,建议把这两个值调到 512M 以上,或者改用外链。
- 文件按 `实例-版本-摘要前 8 位.mrpack` 命名,便于人工核对。

## 卸载

在后台删除插件会**删除五张表以及 storage 里已上传的整合包文件**。只想停用而不丢数据时,用「停用」而不是「删除」。

## 表结构

| 表 | 用途 |
| --- | --- |
| `sitmc_launcher_instances` | 实例(标识、名称、描述、图标、排序、当前版本、游戏/加载器版本、服务器地址、是否必需/启用) |
| `sitmc_launcher_packs` | 每个实例的每个版本(上传文件路径或外链、原始文件名、SHA-1、大小),`(instance_id, revision)` 唯一 |
| `sitmc_launcher_settings` | 启动器更新策略(min/latest/force_update) |
| `sitmc_launcher_announcements` | 公告(标识、标题、摘要、Markdown 正文、展示方式、优先级、开始/结束/发布时间、按钮链接与文案、是否启用) |
| `sitmc_launcher_releases` | 启动器版本(通道、版本号、Markdown 更新说明、发布日期、制品地址、minisign 签名、可选的 target/arch、是否启用) |

表名不加前缀,但都通过 Laravel 的 `Schema`/`DB` 门面访问,所以在 `.env` 里配了 `DB_PREFIX` 也会正常工作。
