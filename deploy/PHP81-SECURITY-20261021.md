# PHP 8.1 安全补丁部署记录

2026-10-21（北京时间）已将宝塔 `/www/server/php/81` 从 PHP 8.1.32 替换为 **PHP 8.1.34 + PHP 官方应急回移补丁**，处理 CVE-2026-109618、CVE-2026-109619。按用户要求，`skin.ysumc.net` 保持宝塔停站状态；正式 PHP-FPM 短暂启动完成验证后已停止，`php-fpm-81.service` 最终为 `inactive/dead`。未修改网站 Nginx 配置，也未恢复公网业务。

## 来源与修复范围

- [PHP 官方安全公告](https://www.php.net/archive/2026.php)：本次为停止维护的 8.1 分支提供最小应急源码回移，不代表恢复 PHP 8.1 安全维护。
- [官方补丁提交](https://github.com/php/php-src/commit/1d7dec178a094e6e75b229ce22754045f8c6070a)：`1d7dec178a094e6e75b229ce22754045f8c6070a`，共 23 个文件。
- 基础源码：`https://www.php.net/distributions/php-8.1.34.tar.xz`。
- 源码 SHA-256：`ffa9e0982e82eeaea848f57687b425ed173aa278fe563001310ae2638db5c251`，已与 PHP 官方发布元数据核对。
- 从官方 GitHub API 保存提交及完整文件补丁；应用后逐文件计算 Git blob SHA-1，23 个文件均与官方提交中的目标 blob 一致。

8.1 应急补丁修改内存耗尽时的退出处理、请求状态清理、相关数组初始化及 OPcache，并在内核中禁用会话上传进度。内存耗尽时会直接退出工作进程，不再执行 PHP shutdown 回调；FPM 可建立新进程处理后续请求。这不同于受支持分支的完整实现，也不能视为 PHP 8.1 的其他历史或未来漏洞全部解决。

仅安装原版 PHP 8.1.34 不包含本次补丁。普通版本输出仍显示 `8.1.34`，应结合构建清单和二进制校验值判断是否为修补版。

## 构建与安装

服务器构建根目录：`/www/server/php-security-builds/20261021-php81`，权限 `700`。

- `downloads/`：官方源码压缩包、官方提交 JSON、应用的补丁。
- `php-8.1.34/`：完整的已打补丁源码和构建产物。
- `source-validation.json`：23 个源码文件的官方 blob 校验结果。
- `configure-command.txt`、`logs/`：构建命令与完整配置、编译、测试日志。
- `stage/`：使用 `INSTALL_ROOT` 安装的隔离产物。
- `build-manifest.json`：补丁、来源、验收结果及七个二进制/扩展的 SHA-256。
- `previous-runtime/`：切换前完整的 PHP 8.1.32 安装目录。
- `backup/php81-before.tar.gz`：修改前完整备份，权限 `600`；`backup/SHA256SUMS` 保存校验值。

保留宝塔原安装前缀、FPM 用户、链接库和已有扩展集合。移除了原配置中已无效的旧 configure 参数，使用 `-O2` 构建；ZIP、Fileinfo 和 OPcache 均重新编译。没有另行升级 OpenSSL、cURL 或其他系统库。

正式路径仍为 `/www/server/php/81`，原宝塔启动脚本和 socket `/tmp/php-cgi-81.sock` 保持一致。`version.pl`、`version_check.pl` 更新为 `8.1.34`；`src` 指向完整的已修补源码目录。旧的 `src.tar.gz` 留在备份中，未保留在新安装目录内。

正式构建清单：`/www/server/php/81/YSUMC-SECURITY-BUILD.json`。全局 `php.ini`、`php-cli.ini` 均设置 `session.upload_progress.enabled = Off`，保留站点 `.user.ini` 中已有的关闭配置。

## 验证结果

| 检查 | 结果 |
| --- | --- |
| 官方源码压缩包 SHA-256、补丁后 23 个文件 blob | 全部匹配 |
| 新旧 CLI 模块清单 | 一致 |
| PHP 官方数组、JSON、ZIP、Fileinfo、会话编解码测试 | 246 通过，5 跳过，0 失败 |
| 启动器现有合同检查 | 19 项通过 |
| eduroam 认证解析检查（模拟上游，无真实账号登录） | 31 项通过 |
| 重新编译的 OPcache 加载 | 通过，未改变生产启用状态 |
| 独立单工作进程池的受限内存耗尽检查 | 进程退出、不执行 shutdown 回调，后续请求由新进程处理 |
| 独立池显式打开上传进度后的普通 multipart 上传 | 上传成功，未建立会话上传进度状态 |
| 实际 FPM 的 ZIP 解压、GD 图片生成、Fileinfo 识别 | 通过 |
| 实际 FPM 的登录页、eduroam 页、启动器 manifest | 全部 HTTP 200，响应格式正确 |
| 正式 FPM 加载的 ini 与上传进度配置 | `/www/server/php/81/etc/php.ini`，关闭 |
| 安装后七个二进制/扩展 SHA-256 | 与候选构建一致 |
| 停站配置及公网响应 | 配置哈希未改变，公网响应与 `bt-stop.html` 字节一致 |

测试框架首次运行时受到宝塔 CLI `open_basedir` 限制；改用独立测试 ini 后完成全部所选测试，没有调整生产 `open_basedir`。安全行为测试只针对独立单工作进程测试池，未向公网发送攻击载荷。

验证脚本见同目录 `php81-security-check.py`；`--oom` 与内核上传进度测试仅允许专用 `/tmp/ysumc-php81-security-test.sock`。普通验证也会对应用发起只读页面请求，应仅由服务器管理员执行。

## 后续操作

网站和 PHP 服务保持停止，恢复访问需要另行启动服务并在宝塔恢复网站。此次没有更改原有开机启动设置。

宝塔后续重装或覆盖升级 PHP 可能丢失定制补丁，应核对 `YSUMC-SECURITY-BUILD.json`、实际文件校验值和新版本是否已包含修复。计划迁移受支持的 PHP 分支；此构建仅为保留 8.1 的应急过渡。

如必须回滚，先确认 FPM 停止、网站继续停用，保留当前修补版目录，再将 `previous-runtime/` 恢复为 `/www/server/php/81`；也可使用已校验的完整备份。旧运行时包含此次漏洞，不应回滚后直接恢复公网服务。
