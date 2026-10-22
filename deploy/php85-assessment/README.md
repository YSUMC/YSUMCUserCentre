# YSUMC 皮肤站 PHP 8.5 部署方案与升级卡点

评估日期：2026-10-21，北京时间。当前最新稳定版为 **PHP 8.5.12**，不是 8.6 预发布版本，依据 [PHP 官方发布信息](https://www.php.net/releases/index.php?json&version=8.5.12)。

结论：服务器具备部署 PHP 8.5.12 的条件，但当前皮肤站不能原样切换。已经在隔离目录成功构建 PHP 8.5.12，并复现当前 Carbon 依赖的致命类型错误。正式 `/www/server/php/81` 仍为带应急补丁的 8.1.34，网站与正式 FPM 继续停用。本次没有更新线上 Composer 依赖、数据库或插件。

## 已完成的验证

隔离运行时：`/www/server/php-compat-assessments/20261021-php85/runtime`。它不在宝塔现有 PHP 安装目录内，也未注册为生产服务。

官方源码 `php-8.5.12.tar.xz` 的 SHA-256：`08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209`。在当前 Ubuntu 24.04 x86_64 服务器上使用系统开发库编译成功，包括 FPM、GD、ZIP、Fileinfo、PDO MySQL、mbstring、intl、OpenSSL 等。新运行时使用系统 OpenSSL 3.0.13；旧 8.1 使用宝塔定制 OpenSSL 1.1.1o，因此后续需要验证 TLS 和签名流程。

同一份生产 `vendor` 的对照测试如下。测试只加载依赖并使用合成数据，不连接业务数据库，不向学校提交账号密码。

| 项目 | PHP 8.1.34 | PHP 8.5.12 |
| --- | --- | --- |
| Composer 自动加载 | 通过 | 通过，存在废弃用法诊断 |
| `Carbon::now()` | 通过 | **TypeError** |
| `CarbonImmutable::now()` | 通过 | **TypeError** |
| Collection 的简单 map | 通过 | 通过，存在诊断 |
| Twig 简单模板渲染 | 通过 | 通过，存在诊断 |
| Laravel Application 对象创建 | 通过 | 通过，尚未完成整个应用启动 |
| Symfony Request 创建 | 通过 | 通过，存在诊断 |
| 启动器现有合同检查 | 19 项通过 | 19 项通过 |
| eduroam 模拟认证检查 | 31 项通过 | 31 项通过 |

原始结果：`compatibility-results.json`。生产依赖和插件清单：`dependency-inventory.json`。这些检查不代表登录、OAuth、数据库、完整 HTTP 页面、真实游戏进服已经在 PHP 8.5 验收。

## 明确的升级卡点

### 1. Composer 锁文件中的版本限制

已核对生产安装的 112 个包与 `composer.lock` 一致。通过当前 Composer Semver 逐项检查，下面两个生产依赖明确拒绝 PHP 8.5.12：

| 包 | 当前版本 | PHP 约束 |
| --- | --- | --- |
| `nette/schema` | 1.2.2 | `>=7.1 <8.2` |
| `nette/utils` | 3.2.6 | `>=7.2 <8.2` |

应在升级副本中更新相关依赖链及锁文件。现有生成的 `vendor/composer/platform_check.php` 只检查最低 PHP 版本，不能证明所有上限约束都满足；不能把自动加载成功或 `--ignore-platform-reqs` 当作兼容性通过。

### 2. Carbon 已复现运行失败

当前 Carbon 2.55.2 的 `Traits/Creator.php:98` 会把 `getLastErrors()` 的返回值传给仅接受数组的函数。在 PHP 8.5.12 上实际报错：

```text
Carbon\Carbon::setLastErrors(): Argument #1 ($lastErrors) must be of type array, false given
```

[PHP 官方文档](https://www.php.net/manual/en/datetimeimmutable.getlasterrors.php)说明，自 PHP 8.2 起，无日期解析错误时该方法可以返回 `false`。因此该问题并非 8.5 独有，迁到 8.2/8.3/8.4 也不能假设绕过它。

本站注册时间、签到、纹理缓存时间、Yggdrasil token 有效期等代码会使用 Carbon，应升级到与目标框架匹配的维护版本并回归；仅屏蔽警告不能修复此 TypeError。

### 3. 框架、模板与认证依赖相互绑定

| 组件 | 当前版本或约束 | 升级含义 |
| --- | --- | --- |
| Laravel | 8.75.0，根约束 `^8.0` | 官方支持的 PHP 范围止于 8.1，安全支持已结束 |
| Passport | 10.2.2，绑定 Illuminate 8 | 必须与框架一起升级，不能只升 Laravel |
| TwigBridge | 0.12.3，支持 Illuminate 5–8、Twig `^2.11` | 需要升级桥接包、替换桥接层或维护适配版本 |
| Twig | 2.14.8 | Twig 2 已停止维护，长期方案迁到 Twig 3 |
| Ignition | 2.17.2，绑定 Illuminate 7/8 | 要随新框架调整错误处理依赖 |
| DBAL / Flysystem / SwiftMailer | 2.13.6 / 1.1.9 / 6.3.0 | 随框架迁移数据库、存储及邮件接口 |

依据：[Laravel 8 支持表](https://laravel.com/docs/8.x/releases)、[Twig 2 官方文档](https://twig.symfony.com/doc/2.x/intro.html)。根 `composer.json` 中的 `php >=8.0.2` 只是下限，不是 PHP 8.5 的支持声明。

建议长期目标采用 **PHP 8.5.12 + Laravel 13 + Twig 3 + 匹配版本的 Passport/桥接层**；Laravel 13 官方支持 PHP 8.3–8.5，安全维护到 2028-03-17。Laravel 12 也支持 8.5，可作为工程上的中间节点，但截至评估日已进入仅安全维护阶段，维护期更短。[Laravel 支持表](https://laravel.com/docs/13.x/releases)

这里的目标不是承诺仅改 Composer 几行即可完成：应按各大版本迁移说明逐段处理 Laravel 8→9→10→11→12→13 的差异。

### 4. OAuth / Janus / Yggdrasil 是重点回归范围

本地与部署代码存在 `Passport::routes()`、token scope、有效期、Lcobucci JWT 调用及自定义授权页等集成。Passport 新版会调整路由、授权视图、OAuth2 Server 接口和类型约束，需依照[官方升级说明](https://github.com/laravel/passport/blob/13.x/UPGRADE.md)适配。

需要验证设备码申请、待授权轮询、授权同意、token 换取/刷新/撤销、Janus 回调、玩家角色列表及真实 Minecraft 加入服务器。保留 APP_KEY、OAuth 密钥、用户/玩家 UUID、client 标识和已有数据关系，不能通过重装或重新生成全部密钥“解决”迁移问题。

Yggdrasil Connect 的 PHP 约束是 `^8.1`，语义上允许 8.5；它本身不是精确锁死 8.1 的约束。现有插件还依赖 Blessing Skin 6 的 API，需要逐个验收。eduroam 解析逻辑在本次 PHP 8.5 单元检查中通过，不能因此认定其完整网页登录已验收。

### 5. 新版 PHP 的诊断与扩展变化

实测旧 Symfony、Guzzle、Carbon 等依赖出现隐式可空参数等废弃诊断。这些诊断本身与 Carbon TypeError 应区别对待；应升级依赖、修正自有代码，不能将所有诊断都视为已经导致 HTTP 失败。[PHP 8.4 迁移说明](https://www.php.net/manual/en/migration84.deprecated.php)、[PHP 8.5 迁移说明](https://www.php.net/manual/en/migration85.php)

不能复用 PHP 8.1 的 `.so` 给 PHP 8.5。GD、ZIP、Fileinfo、PDO、intl 等需针对 8.5 构建；第三方 Redis/Imagick/加密扩展若以后安装，也要检查兼容性。应重新整理 php.ini，避免直接复制旧版废弃配置。

## 推荐部署方式

保留现有停站的修补版 PHP 8.1，另建候选应用目录 `/www/wwwroot/ysumcusercentre-next`、独立数据库副本、storage/cache 和专用 PHP 8.5 FPM 池。测试阶段仅通过本机 socket 或限制访问的预览入口验证。

宝塔若已提供确切的 8.5.12 或更新安全版本，可并行安装；若尚未提供，使用独立目录源码安装。当前服务器已经证明能够编译 8.5.12，面板是否提供该版本不是技术前提。不要覆盖 `/www/server/php/81` 或全局 `/usr/bin/php` 来开展升级实验。

以下模板用于独立运行时方案，尚未启用：

- [BUILD.md](BUILD.md)：已验证的源码编译参数及独立安装步骤。
- `php85.ini.example`：候选应用的 PHP 配置。
- `php85-fpm.conf.example`：仅 Unix socket 的专用池。
- `ysumc-php85.service.example`：独立 systemd 服务。

模板约定运行时安装在 `/opt/ysumc/php85/8.5.12`，配置在 `/etc/ysumc/`，应用在 `ysumcusercentre-next`。需先创建目录、分配 storage/cache 的 www 写权限、复制模板并完成 FPM 配置检查；这些路径与本次只用于评估的 runtime 目录不同。模板可以启动 FPM，但当前未迁移的应用仍不能据此上线。

已使用实际 PHP 8.5.12 检查 `php85.ini.example` 和 FPM 模板，配置检查通过；systemd 单元已把可执行文件路径临时映射到隔离运行时完成静态检查。没有启用或启动该服务。

### 阶段与验收门槛

1. **建立候选副本**：复制必要应用代码和独立数据库；使用独立测试密钥/账号，关闭真实邮件发送与定时任务，避免测试修改线上业务。
2. **更新依赖并适配**：优先解决 Nette、Carbon，再推进框架、TwigBridge、Passport、存储/邮件接口；提交新的 Composer lock，禁止在生产目录直接执行无范围的 `composer update`。
3. **完整回归**：账号密码登录、eduroam、管理员、纹理上传/缩略图/下载、ZIP 插件安装、OAuth/Janus/Yggdrasil、启动器更新与代理接口，以及实际游戏进服。
4. **数据迁移演练**：在数据库副本上演练所有迁移并验证关键计数/UUID/授权关系。若 schema 改变，回滚须包含兼容的数据库方案，不能只切回旧 PHP。
5. **切换**：准备最终数据/密钥同步与备份，再将本站 Nginx `root` 指到候选 `public`、FastCGI 指到 `/run/ysumc-php85/php-fpm.sock`，保留现有 Janus 反代。先 `nginx -t` 再 reload，按业务要求恢复网站。

这次仅完成方案和隔离兼容性评估，没有执行上述生产升级与恢复访问。

## 两种改造范围的取舍

| 路线 | 能解决什么 | 仍需承担什么 |
| --- | --- | --- |
| 保留 Laravel 8，更新兼容依赖并自行补适配 | 可能较快在 PHP 8.5 上跑通现有功能 | Laravel 8、Twig 2 等维护责任仍由本站承担，不是长期安全维护方案 |
| 迁到受支持框架及模板/认证栈 | PHP 和主要框架都进入受支持范围 | 工作量集中在 TwigBridge、Passport、插件及数据兼容，需分阶段迁移 |

推荐第二条作为长期目标。当前最大的卡点是应用依赖与认证链迁移，PHP 8.5 本身的编译与部署已经验证可行。
