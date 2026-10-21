# PHP 8.5.12 迁移副本（2026-10-22）

分支：`codex/php85-compat`。源码副本：`YSUMCUserCentre-php85`。服务器候选目录：`/www/wwwroot/ysumcusercentre-next`。

本轮只修改候选副本；生产 `skin.ysumc.net` 保持宝塔停站页，PHP 8.1 FPM 保持停止。未切换站点根目录、PHP socket、生产数据库或 OAuth 密钥。生产 296 个基线文件 SHA-256 未变。

## 已完成的适配

- PHP 8.5.12、Laravel 13.38.0、Passport 13.10.0、Twig 3.30.0、Carbon 3、JWT 5、Symfony 8；准确版本以根目录 `composer.lock` 为准。安装不使用 `--ignore-platform-reqs`，不关闭安全审计。
- 替换删除的维护／scope 中间件、Passport 路由注册和 TokenRepository 接口；User 实现新版 Passport 契约。
- 为共享 Janus 数据库保留数字 client ID、原有 redirect 列和现有客户端密钥存储格式；OAuth 管理 API 仅向所属用户展示旧前端需要的 secret 字段。保持原有共享密钥契约，不执行在线密钥散列转换。
- 使用配置明确选择 personal-access client，错误 ID 直接失败；支持新授权同意 nonce；验证个人访问令牌、授权码一次性使用、刷新、撤销及 scope 边界。
- 修复 Yggdrasil 的不可变 JWT builder、Token 类型、AuthCode scopes 数组、Carbon 数值参数、email scope 和异常响应；保留既有 UUID 与签名协议。
- 修复 YAML 翻译加载、Symfony Response Cookie、PDO 常量及没有 views 目录的插件导致 `view:cache` 失败。
- 3 个本地兼容包位于 `packages/`，保留原始许可证、上游 commit 与归档校验值。搜索包的本地 1.3.1 版用原生解析器替换 HOA，删除 13 个停止维护依赖，并修复 Laravel 13 的跨字段搜索及日期范围分组。详见 `packages/README.md`。

## 可复用文件与替换方式

迁移提交可用 `git format-patch` 导出，再在同一部署基线的独立分支上 `git am`。交付包同时提供纯迁移 diff；先运行 `git apply --check`，有冲突时逐文件合并。该 diff 不包含生产 `.env`、数据库、上传文件或密钥。

必须一同携带：`composer.json`、`composer.lock`、`packages/`、迁移补丁中的 `app/`、`config/`、OAuth Twig 模板及 Yggdrasil 插件。不要只替换 PHP 二进制或单个 vendor 文件。`vendor/` 应在候选目录按锁文件重新安装：

从全新 Git 克隆开始时，还需按 `deploy/upstream-sources.json` 的固定版本与校验值恢复前端 `public/app/` 等发行资源，并创建可写的 `storage/`、`bootstrap/cache/`；不要带入旧发行版的 vendor。本次从部署副本复制而来的这些资源已参与 HTTP 测试。

```sh
PHP=/www/server/php-compat-assessments/20261021-php85/runtime/bin/php
COMPOSER=/www/server/php-compat-assessments/20261022-migration/composer.phar
cd /www/wwwroot/ysumcusercentre-next
$PHP "$COMPOSER" install --no-interaction --prefer-dist
$PHP "$COMPOSER" check-platform-reqs
$PHP artisan package:discover
$PHP artisan config:clear
$PHP vendor/bin/phpunit
```

使用官方 PHP 8.5.12 源码构建的独立 runtime（源码 SHA-256：`08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209`）。生产所需 GD、PDO MySQL、mbstring、OpenSSL、ZIP、fileinfo、curl、intl、sodium 等扩展必须齐全；本次已验证。测试 FPM 明确设置 `session.upload_progress.enabled=Off`。官方修复说明见 [PHP 8.5.12 ChangeLog](https://www.php.net/ChangeLog-8.php#8.5.12)。

全新独立副本的参考步骤：先运行 `init-isolated.py`，再运行 `prepare-isolated-state.py`，然后同步本分支源代码并安装锁定依赖。这两个初始化脚本要求目标目录、容器／密钥尚未创建；现有候选环境不要重跑。脚本中的目录、容器和端口绑定于本次验证环境，复用前一起调整。

隔离环境使用全新的 APP_KEY、OAuth／Yggdrasil 签名密钥、数据库账号、Cookie、缓存空间；复制的 OAuth 凭据全部撤销，远端联盟地址改为本机。测试账号在专用数据库内产生。生产将来正式升级时应保留原 APP_KEY、签名密钥、UUID、客户端 ID 和环境配置；不能复制候选 `.env`、测试密钥或测试数据库到生产。

`prepare-http-fixture.php` 仅用于隔离库，生成合成账号并写入私有凭据文件。`php-fpm-test.conf`、`nginx-test.conf` 是独立监听的验证配置，不是宝塔生产虚拟主机配置。HTTP 测试由 `http-smoke.py` 执行。Janus 使用相同候选数据库和候选 OAuth 私钥。

本轮测试完成后关闭候选 FPM／Nginx／Janus 和测试数据库容器，保留源码、依赖、密钥和数据库文件。再次验证时在服务器执行 `bash deploy/php85/start-test-stack.sh`，等待服务就绪后执行 `python3 deploy/php85/http-smoke.py`；结束执行 `bash deploy/php85/stop-test-stack.sh`。这些脚本只使用 `candidate` 服务名和测试数据库容器，不更改宝塔配置。候选 Node 已通过 `stage-janus-node.sh` 安装在 `/opt/ysumc-php85-candidate/runtimes/node-v22.23.4-linux-x64/bin/node`；其父目录全部 root 所有，避免应用用户替换运行时。新机器需先校验和安装 Node、构建 `janus/`，再启动候选。

## 验证结果

| 检查 | 结果 |
| --- | --- |
| PHP 回归测试 | 933 项，2,003 条断言通过；包含 899 组旧解析器对照数据 |
| 真实 FPM + Nginx + Janus HTTP | 每轮 38 项检查通过；覆盖缓存、Node 重启、回退探测及恢复 |
| eduroam 模拟上游认证 | 31 项通过；没有使用真实学生密码 |
| 启动器现有协议检查 | 19 项通过 |
| Janus 编译及 Node 测试 | 13 项通过（隔离 Node 22.23.4） |
| PHP 语法检查 | 352 个文件通过 |
| Composer 平台、package discovery、config/view cache | 通过 |
| Composer 安全审计 | 0 个已知安全公告，0 个停止维护依赖；原 13 个包均已移除 |
| Node 修复方案 | SHA-256、目录权限、www 执行、systemd drop-in、重启和回退联调通过 |
| 生产保护检查 | 296 个文件未变，PHP 8.1 inactive，公网响应与停站页逐字节相同；生产 Janus PID 和 unit 未变 |

测试覆盖页面、密码登录、权限、OAuth、Yggdrasil authenticate/refresh/join/hasJoined、签名校验、上传／头像／预览、ZIP 插件安装、设备码同意与选角、跨 PHP/Janus 的刷新和撤销。新增查询测试覆盖权限范围、绑定参数、中文、布尔、日期、关系计数与非法输入。最新证据在 `evidence/maintenance-20261022/`；HTTP 设备码链路是真实服务联调，不是 mock。

本轮没有运行真人 Minecraft 客户端，也没有访问真实校园认证、联盟服务或邮件服务器。因此不把这些外部系统算作验收通过。候选只绑定本机，没有开放公网预览。

## 保留的维护事项与后续切换边界

搜索解析器已替换并移除全部 13 个 HOA／sanmai 包，没有用审计忽略规则掩盖结果。解析器本地 fork 后续由本项目维护；其限制与两处明确的语义修正（裸 `0`、纯空白输入）见 `packages/README.md`。图像处理仍保留通过本轮 GD 回归的旧 Intervention 2 接口，后续可独立迁移。

Janus 继续沿用旧客户端 secret 存储，数据库与备份应保持原有保密边界；未来要散列化必须同时修改 Janus 和 PHP，并设计兼容迁移。本轮没有更改共享表结构，也没有运行生产 migration。

生产 Janus 进程仍存活，但原 Node 路径已经失效。完整修复文件、安装顺序和可用的回退方式已在 [JANUS-NODE-REPAIR.md](JANUS-NODE-REPAIR.md) 准备并通过候选验证。本轮没有修改或重启生产 Janus，也未在 `/opt/ysumc/runtimes` 安装生产运行时。

正式切换是后续单独步骤：备份生产源码／依赖／配置与数据库，在另一个发布目录按锁安装 `--no-dev`，重新验证对应插件、前端资源、真实客户端与外部认证，然后才调整宝塔站点和 FPM。现阶段停站约束保持有效。

SIT 的源码复用评估见 [SIT-ASSESSMENT.md](SIT-ASSESSMENT.md)。
