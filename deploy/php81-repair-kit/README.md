# PHP 8.1 官方应急修复包

适用：Linux 上通过源码安装的 PHP 8.1，重点覆盖宝塔 `/www/server/php/81`。修复 CVE-2026-109618、CVE-2026-109619。包内为官方 PHP 8.1.34 源码及官方 8.1 应急回移补丁，不含站点源码、数据库、配置凭据或预编译二进制。

这是一条保留 PHP 8.1 的应急路线。PHP 8.1 已停止维护，官方将该补丁标记为 EOL 分支的最小应急处理；它不等于重新获得 PHP 8.1 长期支持。不要仅安装原版 8.1.34 后就认为这次漏洞已解决。

该应急补丁会在内存耗尽时直接终止工作进程，不再执行 shutdown 回调，并在内核中禁用 session 上传进度功能。依赖这些行为的应用需要额外验收。

2026-10-21 已在本站服务器使用本包完成一次独立的完整重编译，源码校验、扩展集合及内存耗尽检查通过；`replace.py --plan` 检查通过。本站此前已经完成修复，因此没有为了测试本包再次替换正式 PHP。实际替换步骤需要在目标服务器完成自身验收后执行。

## 修复文件

| 文件 | 用途 |
| --- | --- |
| `php-8.1.34.tar.xz` | 官方完整源码，不依赖宝塔残缺的 `src` 目录 |
| `security.patch` | 官方提交涉及的全部 23 个文件补丁 |
| `official-commit.json` | 官方提交及各个目标文件的 Git blob 校验值 |
| `SHA256SUMS` | 包内文件 SHA-256 清单 |
| `build.py` | 校验、解压、打补丁、编译并安装到 staging；不覆盖现有 PHP |
| `replace.py` | 检查并替换已停止的宝塔 PHP，保留旧目录；不启动服务或恢复网站 |
| `verify-ysumc.py` | YSUMC 专用 FPM、ZIP、图片及页面验收脚本；其他站点需调整域名和路由 |
| `PHP-LICENSE` | PHP 源码许可证；补丁沿用上游对应许可证 |

官方来源：

- 公告：https://www.php.net/archive/2026.php
- 提交：https://github.com/php/php-src/commit/1d7dec178a094e6e75b229ce22754045f8c6070a
- 源码：https://www.php.net/distributions/php-8.1.34.tar.xz
- 源码 SHA-256：`ffa9e0982e82eeaea848f57687b425ed173aa278fe563001310ae2638db5c251`

补丁从官方提交的统一 diff 保存；应用后，脚本逐个验证修改后的文件是否与官方 Git blob 一致。`SHA256SUMS` 能发现传输损坏；来源可信度仍依赖可信渠道取得本包，并与上述官方来源核对。

## 1. 在目标服务器重新构建

将包解压到仅管理员可写的位置，例如 `/root/php81-repair-kit`。不要放在网站 `public` 目录。

需要 Python 3、tar/xz、patch、rsync、C/C++ 编译工具、make、pkg-config，以及原 PHP 所需开发库。脚本复用现有 `php-config --configure-options` 和保留的构建环境，并优先选择现有 PHP 链接的 `/usr/local` 库；不会自动升级系统库。构建日志提示缺少某个开发库时，应补齐该依赖后使用新的工作目录重新构建。

```bash
cd /root/php81-repair-kit
sha256sum -c SHA256SUMS
REPAIR_WORK=/www/server/php-security-builds/php81-repair-$(date +%Y%m%d-%H%M%S)
python3 build.py --work "$REPAIR_WORK" --jobs 4
```

`REPAIR_WORK` 必须是不存在的绝对路径，并位于现有 PHP 安装目录之外。`replace.py` 要求工作目录和 `/www/server/php/81` 在同一文件系统，以便通过目录重命名切换。

只检查源码和补丁、不编译时，可以使用 `--prepare-only`，但该目录不能再用于后续完整构建；请另选新的工作目录。

构建结果位于 `$REPAIR_WORK/stage/www/server/php/81`，完整源码位于 `$REPAIR_WORK/php-8.1.34`。脚本检查源码与补丁、扩展集合、受限内存耗尽行为，并输出 `build.json`。若存在 Redis、Imagick、ionCube 等未由此次 PHP 编译生成的 `.so`，脚本会拒绝继续，应先单独重编译/确认其兼容性并完成额外验收。

## 2. 验证新运行时

先查看 `logs/` 中的 configure/make/stage 日志，确认没有缺失扩展。独立 FPM 池应使用新二进制、单独 socket、单独 pid 和复制后重定向扩展路径的 php.ini。不要同时用新二进制加载旧 `.so` 作为最终验收。

此次 YSUMC 的实测结果：PHP 官方所选测试 246 项通过、5 项跳过、0 失败；19 项启动器检查、31 项 eduroam 模拟认证检查通过；独立 FPM 的内存耗尽、ZIP 解压、图片处理和页面检查通过。其他站点仍应测试其自身登录、上传、数据库、任务队列和插件。

`verify-ysumc.py` 的 `--oom` 与上传进度内核检查只允许专用 `/tmp/ysumc-php81-security-test.sock`，且必须是隔离的单工作进程池。普通验收方式为：

```bash
python3 verify-ysumc.py \
  --socket /tmp/ysumc-php81-security-test.sock \
  --app /www/wwwroot/ysumcusercentre \
  --output "$REPAIR_WORK/fpm-verification.json"
```

## 3. 替换方式

需要替换的是 PHP 引擎及匹配扩展：`bin/php`、`bin/php-cgi`、`bin/phpdbg`、`sbin/php-fpm`、动态扩展，以及对应头文件和构建工具。**不能通过替换网站里的某个 `.php` 文件修复这个漏洞，也不要只替换 `php-fpm` 一个文件。**

先完成独立验证，再停止受该 PHP 运行时影响的所有站点与后台 PHP/队列进程。脚本会拒绝在仍有旧 PHP 进程运行时替换。

```bash
python3 replace.py --work "$REPAIR_WORK" --plan
systemctl stop php-fpm-81.service
python3 replace.py --work "$REPAIR_WORK"
```

脚本保留当前 `etc/` 配置，将 Web 与 CLI 的 `session.upload_progress.enabled` 设为 Off，通过候选目录替换整个安装目录。旧安装目录保存在 `$REPAIR_WORK/previous-runtime`，不会删除。版本文件更新为 `8.1.34`，补丁标识及二进制校验值记录在 `/www/server/php/81/YSUMC-SECURITY-BUILD.json`。

替换脚本不会更改宝塔网站状态，也不会启动 PHP。业务验收通过、且确实准备恢复服务时，再执行 `systemctl start php-fpm-81.service` 并在宝塔恢复相应站点。YSUMC 当前要求保持停站，勿执行恢复步骤。

## 回滚与后续维护

发生问题时保持站点与 FPM 停止；先保留当前修补版目录，再把 `$REPAIR_WORK/previous-runtime` 重命名回 `/www/server/php/81`，检查配置。旧版本包含此次漏洞，回滚后不要直接开放公网服务。切换前还可将原安装目录额外制作 tar 备份。

宝塔重装或覆盖升级可能抹掉自定义补丁。版本字符串 `8.1.34` 不包含补丁标识，后续需检查构建清单和文件哈希。不同服务器应使用本包的源码重新编译，不能假定操作系统、CPU、libc、OpenSSL、cURL、扩展 ABI 与当前机器相同。
