# auth-cas

通过中央认证服务 (CAS) 来登录皮肤站。

## 功能

- [x] 基于 phpCAS 的 CAS 协议实现
- [x] 替换邮箱域名
- [ ] 设置密码（默认为空密码）
- [x] 重置密码（通过 CAS 验证后清空密码）
- [x] 自定义 CAS 服务在皮肤站的显示名称（服务默认被称为“统一身份认证服务”，而登录选项默认被称为“统一身份认证登录”）

> 使用“要求设置密码”（require-passsword）插件可以强制空密码用户设置密码，这样就可以使用该密码登录皮肤站和使用外置登录服务了。

## 配置

在 .env 文件中设置环境变量。

`CAS_HOST` : 认证服务器域名，如 `cas.example.com` 。

`CAS_SERVICE` : 认证服务，即皮肤站地址，如 `https://skin.example.com` 。

`CAS_PORT` : 认证服务器端口（可选），默认为 `443` 。

`CAS_CONTEXT` : 认证服务器上下文（可选），即 CAS 服务所在的路径，默认为 `/cas` 。

`CAS_SERVICEVALIDATE_URL` : 服务令牌 (ST) 验证地址（可选），如 `https://cas.example.com/cas/serviceValidate` ，默认由 phpCAS 根据 CAS 协议版本决定。

`CAS_STORE_HOST` : 替换 `CAS_HOST` 并存储在数据库中的电子邮箱域名（可选）。

## 示例

至少需要配置 `CAS_HOST` 和 `CAS_SERVICE` 环境变量，否则插件无法正常工作。

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com
```

配置对应的可选环境变量以定义自定义认证服务器端口、上下文、服务令牌验证地址等信息。

上下文除了默认的 `/cas` ，还可能为 `/authserver` 、 `/cas/:tenant-id` 等。你可以观察 CAS 登录页面的路径，通常去掉 `/login` 后缀即为需要的值。

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com

CAS_PORT=443
CAS_CONTEXT=/
CAS_SERVICEVALIDATE_URL=https://cas.example.com/cas/serviceValidate
```

插件默认使用 `CAS_HOST` 作为用户电子邮箱域名，将用户邮箱视为 `login_name@cas.example.com` 。配置 `CAS_STORE_HOST=mail.example.com` 后，插件就会使用 `login_name@mail.example.com` 在数据库中查找或注册用户。

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com

CAS_PORT=443
CAS_CONTEXT=/cas
CAS_SERVICEVALIDATE_URL=https://cas.example.com/cas/serviceValidate

CAS_STORE_HOST=example.com
```

## 声明

本项目仅用于学习相关验证过程。使用请自担风险。作者不承担使用此插件带来的任何损失或引发的责任。

## 参见

https://github.com/bs-community/blessing-skin-server

https://github.com/bs-community/blessing-skin-plugins

---

# auth-cas

Log in to skin server with Central Authentication Service (CAS).

## Features

- [x] CAS Protocol Implementation based on phpCAS
- [x] Replace Email hosts
- [ ] Set Passwords (default to empty password)
- [x] Reset Passwords (Clear password after CAS verification)
- [x] Customize Display Name of CAS Service (the service is called _Central Authentication Service_ while the login option is called _Log in with CAS_ by default)

> Use require-passsword plugin to force users with empty password to set one. Then they can use the password to log in to skin server or use external authentication.

## Configuration

Set environment variables in .env file.

`CAS_HOST` : CAS server hostname.

`CAS_SERVICE` : CAS service, or address of skin server, e.g. `https://skin.example.com` .

`CAS_PORT` : CAS server port (optional), default `443` .

`CAS_CONTEXT` : CAS server context (optional), default `/cas` .

`CAS_SERVICEVALIDATE_URL` : Service Token (ST) validation url (optional), e.g. `https://cas.example.com/cas/serviceValidate` , default to be judged by phpCAS according to CAS protocol version.

`CAS_STORE_HOST` : Email hostname that will store in database in replace of `CAS_HOST` (optional).

## Examples

You MUST configure `CAS_HOST` and `CAS_SERVICE` , or the plugin will not work properly.

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com
```

Configure the environment variables accordingly to define custom server port, context and ST validation url.

Context may be `/authserver` , `/cas/:tenant-id` and so on in addition to the default `/cas` . You may take a look at your CAS login path, the value should be the path without trailing `/login`.

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com

CAS_PORT=443
CAS_CONTEXT=/
CAS_SERVICEVALIDATE_URL=https://cas.example.com/cas/serviceValidate
```

The plugin use `CAS_PORT` default as email hostname, treating user email as `login_name@cas.example.com` . After configuring `CAS_STORE_HOST=mail.example.com`, the plugin will use `username@mail.example.com` to lookup or register users in database.

```
CAS_HOST=cas.example.com
CAS_SERVICE=https://skin.example.com

CAS_PORT=443
CAS_CONTEXT=/cas
CAS_SERVICEVALIDATE_URL=https://cas.example.com/cas/serviceValidate

CAS_STORE_HOST=example.com
```

## Statement

This project is only for studying relevant authentication process. USE IT AT YOUR OWN RISK. The author assumes no responsibility for any losses, damages, or liabilities arising from the use of this project.

## See also

https://github.com/bs-community/blessing-skin-server

https://github.com/bs-community/blessing-skin-plugins


## YSUMC PHP 8.5 adaptation

Local version: `0.2.3-ysumc.1`. Installed without enabling the plugin or configuring a CAS provider.

- CAS environment variables are loaded by the application's `config/auth-cas.php`. Deploy that file with this plugin and rebuild the Laravel configuration cache after changing `.env`.
- Browser session initialization is skipped in console commands. Reset/logout pages use the web session middleware; the CAS back-channel logout endpoint remains outside Laravel CSRF middleware.
- CAS server certificates are verified using the system CA bundle. `CAS_CA_BUNDLE` may point to an explicitly trusted CA bundle for a private CA.
- The bundled phpCAS 1.6.1 has three `SessionHandlerInterface` parameters declared explicitly nullable for PHP 8.5, and its deprecated boolean cast and switch-case semicolon syntax are corrected. Reinstalling the upstream vendor directory would overwrite those local compatibility changes.
- No CAS account or remote authentication was exercised during installation.
