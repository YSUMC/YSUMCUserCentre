# SITUserCenter：PHP 8.5.12 源码迁移评估

结论：可以复用这次已验证的 Laravel／Passport／Twig／JWT 迁移方法，但现有 SIT 仓库还不能直接部署到 PHP 8.5.12。它需要恢复完整依赖与插件，再合并核心兼容补丁并验证 SIT 专属业务。没有连接、修改或部署 SIT 实际站点。

评估对象为本地 `learning/SITUserCenter`，对应 [SIT-Minecraft-Axolotl-Club/SITUserCenter](https://github.com/SIT-Minecraft-Axolotl-Club/SITUserCenter) 的 `cc6d5cb46ff9db95aaae935ed9f380a43f6bd471`。本结论仅覆盖这份源码，不推断线上版本。

| 范围 | 源码发现 | 迁移方式／卡点 |
| --- | --- | --- |
| 依赖可重复构建 | 没有 composer.json、composer.lock、vendor | 以原 Blessing Skin 6.0.2 发行版核对遗漏依赖，再采用本分支锁文件及 3 个本地兼容包；确认 SIT 业务没有额外依赖后 install。不能仅复制新 vendor。 |
| 核心框架 | AuthServiceProvider 和 RouteServiceProvider 仍调用 Passport::routes；旧维护和 scope 中间件 | 复用核心兼容提交；保留 SIT 路由与品牌模板。 |
| User／OAuth | 旧 User 不实现 OAuthenticatable；Janus 与 Passport 共用旧客户端表 | 加入新契约、数字 client ID 和共享密钥适配；确认 SIT 是否也使用相同 Janus 协议后选择适配器。 |
| Twig／YAML／缓存 | 同样的旧 loadPath 翻译扩展和插件 views 注册 | 复用 Loader、Response Cookie、PluginManager 等修复。 |
| Yggdrasil 插件 | plugins 目录只有 .gitignore，缺少插件源码 | 这是完整升级验证的前置缺口，无法从该仓库证明其插件版本兼容；必须补齐插件及其配置约定。不能把 YSUMC 定制联盟逻辑直接移植过去。 |
| OAuth scopes | 源码包含旧 Closet.ReadWrtie 拼写；只读 Cache::get('scopes', []) | 迁移时保留旧拼写别名以兼容存量客户端，并增加正确拼写；缓存为空应从 scopes 表重建，避免游戏授权失效。 |
| OA 登录 | OaLoginController 使用原生 cURL、session Cookie 文件、校园端认证 | 安装 ext-curl；移除 PHP 8.5 已弃用的 curl_close 调用或改为 unset；用可替换上游客户端模拟验证码、成功、密码错误、Cookie 过期和超时。源码检查不能替代校园端联调。 |
| 活动／签到／实名 | ActivityController 及 activity_events、activity_signups、users.real_name 等 | 保留 SIT 的 routes/web.php、数据库字段和业务模板；用隔离库验证报名名额、重复报名、签到时间和权限。不能套用 YSUMC 删除校园功能后的路由文件。 |
| CSV 导出 | DataExportController 两次 fputcsv 未指定 escape 参数 | PHP 8.4 起弃用隐式 escape；改为 `fputcsv($handle, $fields, escape: '')`，回归中文 BOM、引号、逗号、换行与空值。 |
| Janus | 仓库含共享 DB 的独立 Node 组件 | PHP 升级之外还要独立构建 Node、生成 Prisma Client、统一 issuer、回调与密钥；复用本轮设备码／撤销测试思路，不能据 PHP lint 判断完成。 |

建议在 SIT 自己的新分支中分三批提交：先补齐可重复构建所需的依赖与插件；再合并框架、OAuth、Twig、文件系统和 JWT 的核心适配；最后处理 OA、活动、CSV、SIT 插件并补业务测试。YSUMC 当前 933 项 PHP 回归（包括 899 组解析器对照）、每轮 38 项 HTTP 检查可作为核心验收模板，但测试数据库、测试账号和品牌／路由断言要改成 SIT 的独立环境。

本轮已移除 13 个停止维护搜索依赖。SIT 可以源码复用 `packages/lorisleiva-laravel-search-string` 本地 1.3.1、锁定依赖和对应搜索测试，保留它自己的可搜索字段与数据权限。Janus 的版本化 Node 路径、SHA-256 校验和 systemd 覆盖方案也可复用，但不能推断 SIT 存在相同的路径故障；运行用户、目录、架构、现有 drop-in 和 issuer 仍需由 SIT 自己确认。本次未修改或部署 SIT。

本次没有承诺 SIT 已升级完成；已经证明共同核心在 PHP 8.5.12 可运行，并明确定位了源码中需要单独处理的部分。是否通过正式升级验收，要以 SIT 完整源码和它自己的隔离测试结果为准。

依据：[PHP 8.5 弃用说明](https://www.php.net/manual/en/migration85.deprecated.php)、[fputcsv 参数变更](https://www.php.net/manual/en/function.fputcsv.php)、[Laravel 13 升级说明](https://laravel.com/docs/13.x/upgrade)、[Passport 13 升级说明](https://github.com/laravel/passport/blob/13.x/UPGRADE.md)。
