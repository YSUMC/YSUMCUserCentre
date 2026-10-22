# PHP 8.5.12 独立源码部署步骤

这是后续候选部署的操作说明。本次实际只在 `/www/server/php-compat-assessments/20261021-php85/runtime` 构建了评估运行时；未执行下面的正式路径安装、服务注册或应用切换。

适用已核对的 Ubuntu 24.04 x86_64 主机，用户和组均为 `www`。使用系统开发库，不使用旧 PHP 8.1 的扩展或宝塔自定义 OpenSSL 1.1.1 库。所需编译工具与开发包通常包括 `build-essential pkg-config libxml2-dev libssl-dev libcurl4-openssl-dev libsqlite3-dev zlib1g-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev libonig-dev libicu-dev libzip-dev libsodium-dev`；依照 configure 结果补齐。

## 1. 下载与编译

使用全新的构建目录。以下配置与实测成功的 PHP 8.5.12 相同，仅修改安装路径和 php.ini 默认搜索位置。不要覆盖已经存在的 `/opt/ysumc/php85/8.5.12`。

```bash
BUILD_DIR=/www/server/php-builds/php85-$(date +%Y%m%d-%H%M%S)
mkdir -p "$BUILD_DIR"
cd "$BUILD_DIR"
curl --fail --location --output php-8.5.12.tar.xz \
  https://www.php.net/distributions/php-8.5.12.tar.xz
echo '08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209  php-8.5.12.tar.xz' | sha256sum -c -
tar -xJf php-8.5.12.tar.xz
cd php-8.5.12

PKG_CONFIG_PATH=/usr/lib/x86_64-linux-gnu/pkgconfig:/usr/share/pkgconfig \
CFLAGS='-O2' ./configure \
  --prefix=/opt/ysumc/php85/8.5.12 \
  --with-config-file-path=/etc/ysumc \
  --enable-fpm --with-fpm-user=www --with-fpm-group=www \
  --enable-mysqlnd --with-mysqli=mysqlnd --with-pdo-mysql=mysqlnd \
  --with-freetype --with-jpeg --with-webp --with-zlib \
  --enable-xml --disable-rpath --enable-bcmath --enable-shmop \
  --enable-sysvsem --with-curl --enable-mbregex --enable-mbstring \
  --enable-intl --enable-ftp --enable-gd --with-openssl --with-mhash \
  --enable-pcntl --enable-sockets --enable-soap --with-gettext \
  --enable-fileinfo --with-zip --with-sodium
nice -n 10 make -j4
make install INSTALL_ROOT="$BUILD_DIR/stage"
"$BUILD_DIR/stage/opt/ysumc/php85/8.5.12/bin/php" -n -v
"$BUILD_DIR/stage/opt/ysumc/php85/8.5.12/bin/php" -n -m
```

PHP 8.5 的此构建已包含 OPcache；不要复制旧版 `opcache.so`，也不要直接套用 8.1 的 `zend_extension` 路径。构建及扩展检查通过后，再将 staging 中的独立运行时安装到新路径：

```bash
test ! -e /opt/ysumc/php85/8.5.12
install -d -m 0755 /opt/ysumc/php85
cp -a "$BUILD_DIR/stage/opt/ysumc/php85/8.5.12" /opt/ysumc/php85/8.5.12
```

## 2. 配置候选运行时

先准备已完成依赖迁移的应用副本 `/www/wwwroot/ysumcusercentre-next`，以及独立 `.env`、数据库、缓存、storage 和 `bootstrap/cache` 写权限。不要直接复制原 `.user.ini` 中指向旧目录的 `open_basedir`；检查并改成候选目录。

在本说明和三个 `.example` 文件所在目录执行：

```bash
install -d -m 0755 /etc/ysumc
install -m 0644 php85.ini.example /etc/ysumc/php85.ini
install -m 0644 php85-fpm.conf.example /etc/ysumc/php85-fpm.conf
install -m 0644 ysumc-php85.service.example /etc/systemd/system/ysumc-php85.service
/opt/ysumc/php85/8.5.12/sbin/php-fpm -t \
  -c /etc/ysumc/php85.ini -y /etc/ysumc/php85-fpm.conf
systemd-analyze verify /etc/systemd/system/ysumc-php85.service
systemctl daemon-reload
```

CLI/Composer 命令也应显式使用此 PHP 和 `-c /etc/ysumc/php85.ini`，例如 `php -c ... /path/to/composer.phar ...`；服务单元已显式指定配置文件。避免调用系统默认 `php` 导致仍使用 8.1。

当前用户要求保持停站，以上步骤不包含启动或启用服务。以后候选应用与数据库准备完毕时，可以单独启动该服务，通过 `/run/ysumc-php85/php-fpm.sock` 验收，不更改现有网站。

## 3. 宝塔接入与切换

测试期间使用仅本机或限制来源的预览站点，`root` 指向候选目录的 `public`，FastCGI 使用 `unix:/run/ysumc-php85/php-fpm.sock`。不要同时保留宝塔 `enable-php-81.conf` 中指向旧 socket 的 PHP location。保留 Laravel 的 `try_files` 路由与既有 Janus 反代规则。

完整应用和真实游戏验收、最终数据库迁移与备份完成后，才调整 `skin.ysumc.net`。现有站点目前使用 `/www/server/stop` 停站页；恢复访问属于单独的上线动作。切换前执行 `nginx -t`。如采用此独立运行时，systemd 服务、版本更新与配置由本站维护，不会自动成为宝塔的 PHP 版本条目。

回滚必须同时考虑应用版本、数据库 schema 与运行时。保留旧站、已打补丁的 PHP 8.1、OAuth 密钥和数据备份，但不能将旧数据库恢复策略等同于只改回一个 socket。
