# YSUMCL 启动器插件

基于 SIT-Minecraft 的 sitmc-launcher，供 YSUMC User Centre 的 PHP 8.5 / Laravel 13 适配版使用。仓库只包含插件源码，不是完整皮肤站。插件版本为 1.4.0，与启动器客户端版本（如 1.0.9）分别维护。

内部插件 ID 仍为 `sitmc-launcher`，命名空间仍为 `SITMinecraft\Launcher`，保留原有数据表及接口地址，便于现有站点升级。MUTMC 功能已移至独立的 `mutmc-launcher` 插件，本仓库不包含该插件。

## 功能

- YSUMCL 实例、整合包、公告、版本更新和云地图配置。
- mc-interop v1 本站清单、独立 friends 接口、友站同步和旧版启动器兼容投影。
- 玩家身份核验、皮肤接口、按账号等级下发实例／公告／版本。
- 用户管理页面的等级和单部门标签；普通用户、社员、部长、社长、管理员、超级管理员。
- 按等级和部门配置后台页签的不可访问／只读／可管理权限，超级管理员固定拥有全部权限。

## 安装与升级

1. 先备份站点数据库、现有插件目录和整合包存储。
2. 将本仓库内容放到站点 `plugins/sitmc-launcher/`，目录名必须保留该 ID。
3. 按需把 `config.example.php` 复制到站点 `config/sitmc-launcher.php`，在服务器环境配置 `YSUMC_PACK_DISK`、`YSUMC_USERINFO_URL` 和 `YSUMC_CURSEFORGE_API_KEY`。不得提交实际密钥。
4. 在插件管理中启用；表结构在启用或首次访问时增量建立。手工替换已启用插件时，把 `assets/` 同步到 `public/plugins/sitmc-launcher/assets/`。
5. 使用站点对应的 PHP 执行 `artisan config:clear`、`route:clear`、`view:clear`、`twig:clean`，按站点部署方式刷新 PHP-FPM。

需要皮肤站提供现有 web/API 中间件、Twig 页面、用户与角色模型。Yggdrasil 令牌核验和皮肤功能还依赖已正确配置的 Yggdrasil Connect / Janus 服务。适配目标是 YSUMC User Centre；未声称可直接运行于所有原版 Blessing Skin 6.x 环境。

## 管理入口

| 地址 | 用途 |
| --- | --- |
| /admin/launcher | 实例、整合包和更新策略 |
| /admin/launcher/announcements | 公告 |
| /admin/launcher/releases | 启动器版本、下载地址、签名与更新说明 |
| /admin/launcher/interop | 友站互联和对外发布 |
| /admin/users | 在原权限编辑按钮内调整等级及部门 |
| /admin/plugins/config/sitmc-launcher | 后台权限组配置，仅超级管理员可编辑 |

MUTMC 的后台权限键仍保留，配合独立 MUTMC 插件使用，无需重新分配已保存的授权。

## 用户权限 CSV 批量管理

`/admin/users` 提供“导出所有用户权限 CSV”和“校验并批量导入”。导出覆盖全站用户，不受当前搜索或分页影响；包含 UID、邮箱、昵称、用户等级、部门、账号状态、后台权限组和校验标记。只有用户等级和部门会被导入，后台页签权限继续由现有权限组矩阵决定，不创建个人权限例外。

等级可填中文标签或 `normal / member / minister / president / admin / super_admin`；部门可填宣传部、运维部、技术部、建筑部或 `publicity / operations / technology / building`。社员和部长必须恰好一个部门，其他等级留空。保留表头、UID、邮箱和校验标记；可移除不需修改的行，不会删除账号。文件导出为带 BOM 的 UTF-8 CSV，兼容中文 Excel 的 GB18030 CSV 导入，每次上限 10 MiB / 20000 人。

导出要求用户管理只读权限，导入要求可管理权限，逐人沿用现有等级、部门和后台权限上限检查。未修改行跳过；封禁及仅认证账号不能借导入恢复。导入锁定账号，核对导出时的权限标记，先整批校验后在一个事务内更新并写入现有审计表；重复 UID、未知账号、过期标记、非法部门或越权均整批拒绝。不会改变自己的管理权限或移除最后一位超级管理员。CSV 响应不缓存，用户提供的昵称和邮箱进行公式防护，上传文件不持久保存。

## 通过链接发布整合包

实例编辑页的外链发布只需填写公开 HTTPS 下载直链和制品格式。服务器完整读取文件后自动计算 SHA-1、SHA-512 和实际字节数，并保存到版本记录；忽略旧表单提交的手填哈希和大小。读取失败时保留输入，不新增版本，也不改动当前版本。

计算采用流式读取，仅保留哈希状态和少量响应头，不生成临时下载文件或磁盘缓存，因此成功、失败和中断均无下载缓存残留。单次读取上限为 2 GiB、55 秒，最多跟随 5 次 HTTPS 跳转；每次跳转都检查公网地址并固定 DNS 解析结果。拒绝空文件、不完整响应和内网地址；除 raw 外还检查 ZIP 文件头。发布记录保留原始下载链接，客户端继续从原链接下载。外部文件内容变更后需重新发布，以更新哈希和大小。

## 玩家下载入口

`/user/launcher` 为登录后的 Windows 64 位启动器下载页，也会加入用户侧栏。未登录时跳转到皮肤站登录页，成功后返回下载页。

页面使用后台“启动器版本”中当前用户有权查看的最新正式版（按版本号排序），展示大小、更新说明和可用的 SHA-256。点击 `/user/launcher/download` 时再次核验网站会话与版本接收权限，直接跳转到该版本填写的制品地址；不会改写成皮肤站托管地址，也不接收请求参数传入的下载目标。最新版制品缺失时显示暂不可用，不悄悄回退到旧版。更换制品地址或发布新版后，下次访问立即生效。

此登录限制仅用于网页下载入口，现有启动器自动更新接口保持原有规则；已获知的外部制品链接仍由其存储服务控制访问。下载页和跳转响应不允许缓存。功能不涉及用户数据或发布表结构变更。

## 客户端接口

| 地址 | 用途 |
| --- | --- |
| /api/launcher/manifest | 当前账号可接收的本站实例清单 |
| /api/launcher/identity | 当前账号等级、部门、标签及角色 |
| /api/launcher/packs/{instanceId}/{revision} | 整合包下载并重新核验接收权限 |
| /api/launcher/announcements | 公告数组 |
| /api/launcher/update/latest | 最新版本摘要 |
| /api/launcher/update/versions | 平台版本和制品目录 |
| /api/launcher/update/releases | 版本列表 |
| /api/launcher/update/{target}/{arch}/{currentVersion} | Tauri 动态更新元数据 |
| /api/launcher/skins | 衣柜、上传及删除材质；equip 子路径设置穿戴 |
| /.well-known/mc-interop-v1.json | 本站公开 mc-interop 文档 |
| /api/launcher/interop/v1/site | 本站清单的备用地址 |
| /api/launcher/interop/v1/friends | 独立友站目录（亦提供 /api/launcher/friends） |
| POST /api/launcher/interop/v1/refresh | 使用独立同步口令刷新友站快照 |

受限内容通过 Authorization Bearer 核验当前 Yggdrasil / Janus 令牌和角色归属，再读取服务端等级。匿名及旧客户端只接收公开内容；限制采用精确白名单，不自动包含更高等级。公开友站接口不导出受限内容。已公开的外部 CDN 链接仍受其自身存储权限控制。

支持旧版聚合目录；新启动器使用独立 friends 接口，发送 `X-YSUMC-Catalog: split-v1`。定时同步口令仅放请求头，禁止写入本仓库。

## 存储、拆分与回滚

使用 `sitmc_launcher_*` 表和所选磁盘下的 `launcher-packs/`。用户分级、后台权限及审计表属于本插件。停用保留数据；在插件管理中删除会删除本插件数据及上传包，请谨慎区别。

1.4.0 不再注册 MUTMC 的菜单和接口。安装独立 MUTMC 插件才能继续提供原 /api/launcher/mutmc/* 与 /admin/launcher/mutmc 地址；该插件使用独立表和存储。拆分部署必须先检查旧表中的 MUTMC 记录，有数据时需要单独迁移；禁止直接启用空表来替代既有目录。YSUMC 2026-10-26 的拆分检查中旧 MUTMC 记录均为零。

回滚需要成套恢复两个插件及启用状态，不能把旧双品牌插件与新 MUTMC 插件同时启用。已有受众限制或后台权限时，不可回退到不理解这些限制的旧版本。源码维护仓库： https://github.com/YSUMC/ysumcl-launcher-plugin 。
