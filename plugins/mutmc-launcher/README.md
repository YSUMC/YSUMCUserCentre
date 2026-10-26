# MUTMC 启动器插件 1.0.0

从 YSUMC 的 sitmc-launcher 拆分，独立管理 MUTMC 的实例、整合包、公告、版本更新与云地图。命名空间 MUTMC\Launcher，插件 ID mutmc-launcher。目标环境：YSUMC User Centre 的 PHP 8.5 / Laravel 13 适配版。

## 安装与配置

将插件放入 plugins/mutmc-launcher，在插件管理中启用。配置按钮进入 /admin/launcher/mutmc，其下有实例、公告、版本三个页签。需要单独配置环境时，将 config.example.php 复制为站点 config/mutmc-launcher.php；MUTMC_PACK_DISK、MUTMC_USERINFO_URL、MUTMC_CURSEFORGE_API_KEY 分别配置磁盘、Janus userinfo 和 CurseForge 密钥。未配置时沿用站点已有的 YSUMC 环境值，新的显式值优先；密钥只保存在服务器环境。

不得与 sitmc-launcher < 1.4.0 同时启用，这些旧版已经注册相同 MUTMC 地址。升级时先备份并检查旧表中 distribution_brand=mutmc 的实例、公告、版本、关联整合包以及 mutmc.* 设置。有数据时必须先迁移；本插件不自动复制或删除旧表数据。YSUMC 本次拆分前这些记录均为空。

## 兼容接口

- /api/launcher/mutmc/manifest
- /api/launcher/mutmc/packs/{instanceId}/{revision}
- /api/launcher/mutmc/announcements
- /api/launcher/mutmc/update/latest、versions、releases
- /api/launcher/mutmc/update/{target}/{arch}/{currentVersion}
- /api/launcher/mutmc/curseforge/{path}

原管理地址 /admin/launcher/mutmc、其下 announcements 和 releases 均不变。数据为空时返回空清单／空列表或 204，不回退到 YSUMCL 的内容。更新通道优先读取查询参数，然后 X-MUTMC-Channel，仍兼容原 X-YSUMC-Channel / X-Axolotl-Channel。

## 权限与数据隔离

实例、公告、版本保留按等级下发。通过同站 Janus userinfo 核验 Bearer 令牌及角色归属；如果存在 sitmc_launcher_memberships 则只读其等级和部门，否则按核心普通用户／管理员／超级管理员判断。本插件不创建、修改或删除用户分级与后台权限表。

启用 YSUMCL 权限插件时，继续沿用 mutmc_instances、mutmc_announcements、mutmc_releases 三个后台权限项，配置按钮复用实例权限；只读权限仍由服务端限制。未启用该权限插件时，后台使用皮肤站核心管理员检查。

拥有五张独立表 mutmc_launcher_instances、packs、settings、announcements、releases。整合包仅保存在所选磁盘的 mutmc-launcher-packs/ 下，实例 ID 不再与 YSUMCL 共用唯一约束。旧 YSUMCL 数据和文件不迁移、不删除。

停用只关闭路由和菜单并保留数据。在插件管理中删除则删除本插件五张表及其整合包；不触碰 YSUMCL、用户分级或权限配置。回滚前若新插件已经有数据，应先导出并迁回旧结构，不能直接恢复旧版空目录。

## 维护

替换源码后清除 route、view、Twig 缓存；改环境配置时同时清除 config 缓存，刷新 PHP-FPM。MUTMC 现有客户端接口无需改地址；客户端是否发送身份令牌，决定其能否接收配置为受限的内容。
