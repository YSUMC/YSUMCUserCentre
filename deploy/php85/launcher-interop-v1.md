# launcher 互操作端点：本地验收与后续部署

**部署更新：已按用户后续授权合并至 `YSUMC-dev` 并在 PHP 8.5.12 上部署、恢复访问。下文保留原本地开发阶段记录；新增修复、生产验收和回滚见 [本次发布记录](REVIEW-RELEASE-20261022.md)。**

状态：独立分支 `codex/launcher-interop-v1`，基于本地安全修复提交 `cae6bad`；尚未部署，线上继续停站和停止 PHP。参考仓库 `learning/sitmc-launcher` 已从 `04afd85` 快进到 `45935d7`（SIT 1.3.0）。实现按用户提供的 mc-interop v1 文档适配，插件版本为 1.3.1。

## 本地验证

在站点根目录，使用 PHP 8.5.12、已安装的 Composer 依赖运行：

```sh
php vendor/bin/phpunit -c phpunit.interop.xml
php tests/Interop/validate-schema.php /path/to/composer.phar
php tests/launcher-contract.php
php vendor/bin/phpunit -c phpunit.security.xml
```

互操作测试使用 SQLite 内存数据库、隔离临时存储、模拟网络，不读取生产 `.env`。Schema 校验直接使用协议附录 B 的副本，复用 Composer PHAR 内置 JSON Schema 验证器；不额外引入生产依赖。安全测试中已有图像库弃用提示与本次改动无关。

2026-10-22 本地结果：互操作/导入 32 项、170 断言通过；安全回归 23 项、92 断言通过（18 类既有图像依赖弃用提示）；原有契约 19 项通过；协议 Schema 3 组通过；插件 PHP 语法、后台 Twig 编译和中英文 YAML 校验通过。

覆盖：匿名入口、管理员中间件、选择发布与撤回、当前版本下载、旧清单防绕过、纯服务器与省略端口、两种摘要及 SHA-512 单独使用、128 字符 ID、条件缓存、Host 防注入、友站重试/超限/身份变化/失败保留/过期/304恢复/冲突、内网地址、ZIP 校验及元数据解析、版本递增、Twig 编译。

尚未进行生产 Nginx/PHP-FPM、真实 MySQL 并发迁移、真实跨站 DNS/TLS 和启动器安装联调；这些需在后续授权部署时验收。当前测试通过不代表线上已切换。

## 后续部署顺序（本次未执行）

1. 停站期间备份数据库、`plugins/sitmc-launcher`、`app/Http/Middleware/DetectLanguagePrefer.php`、Nginx 站点配置以及 `storage/app/launcher-packs`。记录数据库迁移前表结构。
2. 部署本分支的完整插件及语言 Cookie 中间件改动，保留站点现有环境配置、密钥及上传数据。生产 PHP 仍使用已经准备好的 8.5.12。不要先把所有实例勾选公开。
3. 在隔离/维护环境触发插件表结构升级，确认旧实例、公告、更新记录及制品未丢失。填写 `site_id=ysumc`、`site_domain=skin.ysumc.net`；站点名读取现有设置。
4. 后台 `/admin/launcher/interop` 逐项选择公开实例，核对服务器、版本、摘要和下载 URL。没有服务器、加载器拼写错误或私网制品不得发布。先手动同步一个允许的友站，再决定是否添加后台显示的定时任务。
5. 宝塔常见规则会把 `/.well-known/` 当成静态目录。为**唯一精确路径**添加转发，保留现有 ACME 配置；不要放开整个隐藏文件目录。示例只作合并参考：

```nginx
# 放在对应 HTTPS server 块内，复用该站点已有 PHP front-controller 配置。
location = /.well-known/mc-interop-v1.json {
    rewrite ^ /index.php last;
}
```

原始 `REQUEST_URI` 必须保留以供 Laravel 匹配路由。若现有 Nginx 模板使用其他重写方式，应将该精确路径转给 `/api/launcher/interop/v1/site` 并验证两入口响应体相同。修改后先 `nginx -t`，再在维护环境重载测试；不可覆盖宝塔整份配置。

v1 入口拒绝明文 HTTP（426 JSON）；PHP-FPM 必须从可信 TLS 入口得到正确的 HTTPS 参数。若前面还有代理，需先配置宿主的可信代理，不能直接信任任意客户端提交的 `X-Forwarded-Proto`。

6. 验证 200/304、没有 Set-Cookie、`Cache-Control` 正确；未发布实例、历史制品返回 404；后台写入须登录、管理员权限和 CSRF。校验真实下载长度/摘要及一个实际启动器的安装与进服。
7. 同步口令只能使用 `X-Interop-Token`，不得出现在 URL、访问日志或公共文档。公开读取不会触发同步。本文不包含真实口令。
8. 所有验收完成且用户通知后才能开启生产 PHP 和恢复站点。

## 回滚

保持停站，恢复部署前的插件、中间件、数据库和 Nginx 配置，然后验证旧服务。不要通过“删除插件”回滚，该操作会删除插件数据；新增表/列通常可保留，但若要完全恢复原状态应使用此次数据库备份。回滚到旧插件会失去新版本的发布范围和下载限制，因此必须继续停站，重新确认访问策略后才能恢复访问。
