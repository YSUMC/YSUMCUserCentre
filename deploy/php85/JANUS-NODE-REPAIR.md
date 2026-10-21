# Janus Node 路径修复方案（待后续维护窗口执行）

本轮只验证候选环境，未安装生产 drop-in、未 daemon-reload、未重启生产 Janus、未恢复皮肤站访问。生产 unit 的 `ExecStart=/usr/bin/node dist/main.js` 指向不存在的文件；存活进程实际使用的 `/www/server/nodejs/v22.18.0/bin/node` 也已被删除。

## 修复文件与目标路径

| 文件 | 用途 |
| --- | --- |
| `stage-janus-node.sh` | 核对固定 SHA-256，将官方完整 Node 发行包解压到新的版本目录；拒绝覆盖既有版本，不操作 systemd |
| `check-janus-node.sh` | 检查运行时及父目录 root 所有且不可被普通用户写入，并以 www 验证版本、主程序和模块可读性 |
| `janus-node.conf` | 生产服务的 ExecStart/PATH 覆盖文件；保留原 User、WorkingDirectory、沙箱与重启策略 |
| `capture-janus-rollback.py` | 从仍存活进程只读保存 Node 可执行文件和 unit（包括 drop-in），供原路径已消失时恢复 |
| `janus-node-rollback.conf` | 临时回退配置，使用独立保存的旧 Node 绝对路径，不再引用失效的 `/usr/bin/node` |

生产目标：`/opt/ysumc/runtimes/node-v22.23.4-linux-x64/bin/node`。不依赖宝塔所选 Node 版本、shell profile、nvm 或 `/usr/bin/node` 软链接。当前候选使用 `/opt/ysumc-php85-candidate/runtimes/node-v22.23.4-linux-x64/bin/node`，与生产目标分开。

官方发行包：[Node 22.23.4 Linux x64](https://nodejs.org/dist/v22.23.4/node-v22.23.4-linux-x64.tar.xz)，[官方校验列表](https://nodejs.org/dist/v22.23.4/SHASUMS256.txt)。固定 SHA-256：

```text
1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce
```

该版本是本轮已经构建和联调的版本，不使用随时间变化的 `latest` 路径。脚本适用于当前 Linux x86_64/www 环境；其他架构需另选官方归档和校验值。

## 本轮已完成的验证

- 官方归档校验、以 www 执行、完整 root 目录权限检查；损坏归档和覆盖既有运行时均被拒绝。
- 将生产 unit 复制到私有测试目录，只替换候选 WorkingDirectory 和运行时路径；`systemd-analyze verify` 验证对应 drop-in。该检查没有加载生产配置。
- 候选 Janus 在 `ProtectSystem=strict`、`ProtectHome=true`、`PrivateTmp=true`、`NoNewPrivileges=true`、`UMask=0027` 下启动；编译和 13 项 Node 测试通过。
- 新运行时启动、重启、旧运行时回退探测、恢复新运行时后，分别通过 38 项真实 PHP/FPM/Janus HTTP 检查（含设备码、选角、签发、刷新、跨服务撤销）。
- 已在服务器私有目录保存旧进程二进制 `/www/server/php-compat-assessments/20261022-migration/janus-rollback-20261022/node`；版本 `v22.18.0`，SHA-256 `ccacb2d6d87ba6d118b2bffc1ae33747a05cc5fc6b1f1ec4fc1d420b687e952d`。其回退探测只连接候选数据库。私有快照不进入交付 ZIP。

## 将来正式修复的操作顺序

以下为准备好的生产操作步骤，**本轮没有执行**。Janus 切换独立于 PHP／宝塔开站；执行这些步骤也不应修改停站配置。

1. 在维护窗口重新只读保存当前 unit 与存活进程（新目录名不可覆盖旧快照）。如果此时进程已退出，使用本轮保存且校验通过的私有快照，不能假设 `/proc/.../exe` 仍存在。

   ```sh
   cd /path/to/reviewed-release/deploy/php85
   python3 capture-janus-rollback.py /root/ysumc-janus-before-node-switch
   ```

2. 以 root 准备新运行时。`ARCHIVE` 可指向本轮已下载文件；新机器从上述官方地址下载。目录已存在时脚本会拒绝覆盖，应先检查其来源和完整性。

   ```sh
   ARCHIVE=/www/server/php-compat-assessments/20261022-migration/node-v22.23.4-linux-x64.tar.xz
   bash stage-janus-node.sh "$ARCHIVE" /opt/ysumc/runtimes
   bash check-janus-node.sh /opt/ysumc/runtimes/node-v22.23.4-linux-x64/bin/node /www/wwwroot/ysumcusercentre/janus
   ```

3. 保存 `/etc/systemd/system/ysumc-janus.service` 和现有 `.service.d/` 后，安装覆盖文件。应先检查现有 drop-in 是否还有其他 ExecStart/PATH 覆盖；有则合并并复核最终配置，不能盲目依赖文件名顺序。

   ```sh
   install -d -o root -g root -m 0755 /etc/systemd/system/ysumc-janus.service.d
   install -o root -g root -m 0644 janus-node.conf /etc/systemd/system/ysumc-janus.service.d/20-node-runtime.conf
   systemd-analyze verify /etc/systemd/system/ysumc-janus.service
   systemctl daemon-reload
   systemctl show ysumc-janus.service -p ExecStart -p Environment
   # 确认最终 ExecStart 是上述 v22.23.4 绝对路径后，才执行维护窗口内的重启。
   systemctl restart ysumc-janus.service
   systemctl is-active ysumc-janus.service
   readlink /proc/$(systemctl show ysumc-janus.service -p MainPID --value)/exe
   journalctl -u ysumc-janus.service -n 60 --no-pager
   ```

4. 确认进程实际 exe 指向新版本、没有循环重启，私有健康／授权检查通过。候选 `http-smoke.py` 带有测试账号和本机端口，不能直接用于生产。正式业务验证需使用届时授权的测试客户端。宝塔停站和 PHP 8.1 停止状态继续保持。

## 回退方式

仅删掉 drop-in 会重新指向不存在的 Node，不能作为回退。新运行时无法启动时，先停 Janus，保持网站停站，检查原因；需要恢复旧行为时才使用保存的基线二进制。`v22.18.0` 只用于临时恢复旧状态，不能作为完成 Node 安全升级的结论。

先校验私有快照的 `manifest.json` 与二进制 SHA-256，再使用新的专用路径：

```sh
SNAPSHOT=/www/server/php-compat-assessments/20261022-migration/janus-rollback-20261022
# manifest.json 的 SHA-256 必须与下列输出一致。
sha256sum "$SNAPSHOT/node"
install -D -o root -g root -m 0755 "$SNAPSHOT/node" /opt/ysumc/runtimes/node-rollback-baseline/bin/node
runuser -u www -- /opt/ysumc/runtimes/node-rollback-baseline/bin/node --version
install -o root -g root -m 0644 janus-node-rollback.conf /etc/systemd/system/ysumc-janus.service.d/20-node-runtime.conf
systemd-analyze verify /etc/systemd/system/ysumc-janus.service
systemctl daemon-reload
systemctl show ysumc-janus.service -p ExecStart
systemctl restart ysumc-janus.service
systemctl is-active ysumc-janus.service
```

保留旧快照与新运行时，直到正式维护验收结束。全程不覆盖 `.env`、OAuth 私钥、数据库或原 Janus 源码，也不切换 PHP 站点。
