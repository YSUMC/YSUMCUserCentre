# 生产 Janus Node 26.12.1 修复记录

2026-10-22 按用户明确授权，生产 `ysumc-janus.service` 已接到新安装的 Node 26.12.1 并重启。皮肤站仍为宝塔停站页，生产 PHP 8.1 FPM 仍停止，未切换 PHP 8.5 或站点目录。此前交付材料中的 Janus `203/EXEC` 状态为修复前记录。

## 已应用的配置

- Node：`/www/server/nodejs/v26.12.1/bin/node`。二进制及父目录均由 root 所有，www 可执行。
- systemd drop-in：`/etc/systemd/system/ysumc-janus.service.d/20-node-runtime.conf`，内容见同目录源码文件 `janus-node26.conf`。
- 清空继承的 `ExecStart`，设为上述 Node 绝对路径及原 `dist/main.js`；PATH 也显式指向同一版本目录。
- 原 unit、www 用户、生产 Janus 工作目录、沙箱和重启策略保持原值。没有创建 `/usr/bin/node` 软链接，也没有改动生产应用、依赖、环境文件、数据库或签名密钥。
- 仅执行 Janus 的配置重载和重启，没有操作生产 PHP、Nginx 或候选服务。

执行前先离线验证实际 unit 与 drop-in 组合，再安装覆盖文件、执行 `systemctl daemon-reload` 与 `systemctl restart ysumc-janus.service`。当前 Node 版本目录是运行依赖，后续在宝塔卸载或清理 Node 时需保留它，或先维护服务路径。

## 验证范围

`verify-janus-node26.py` 验证服务 active/running、实际 `/proc/<PID>/exe` 指向 Node 26.12.1，监听仅为 `127.0.0.1:13010`，并完成：

- OIDC 发现文档返回 200，issuer 和设备码支持正确。
- JWKS 返回 200，仅包含公钥参数。
- 使用不存在的测试客户端执行一次授权码交换校验，经过既有 Prisma 客户端读取后返回预期 401 `invalid_client`，没有签发令牌。
- userinfo 正确返回到既有 PHP 路由的 303 重定向，不跟随重定向启动登录。
- 两次检查间 PID、systemd invocation 与自动重启累计数不变。累计重启数包括修复前的失败次数，不等于修复后发生了重启。
- 333 个受保护文件未变，公网响应与停站页逐字节相同，生产 PHP 8.1 保持 inactive。

新 Node 在候选源码上此前已完成构建和 13 项测试；生产与候选的关键 Janus 构建文件及 package-lock 一致。本次生产验证没有恢复 PHP 登录、创建授权或更改实际账号数据。完整登录流程须等待以后明确授权开站后验证。

执行证据见 `evidence/janus-node26-20261022/verification.json`。重复执行校验脚本时，它会要求进程与第一次健康检查相同；后续有计划重启后，需要为新的维护窗口建立新的基线。

## 备份及回退

本次私有备份目录：`/www/server/php-compat-assessments/20261022-migration/janus-node26-repair-20261022/`。包含原始 unit、有效配置、修复前状态、受保护文件校验值和修复后证据。

修复前不存在 drop-in，但直接删除新 drop-in 会退回失效的 `/usr/bin/node`，无法恢复运行。若未来 Node 26 需要紧急回退，可使用已保存并在候选环境验证的 Node 22.18.0 基线二进制和 `janus-node-rollback.conf`；具体恢复及校验步骤见 `JANUS-NODE-REPAIR.md` 的回退部分。旧版本只用于临时恢复，应在后续维护中重新选定并验证运行时。

本次没有修改候选 PHP 8.5 环境：它继续独立运行，使用自己的数据库、密钥、Node 22.23.4 和回环端口。
