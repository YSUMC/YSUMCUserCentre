# OAuth 1.0.6 — YSUMC PHP 8.5 / Laravel 13 compatibility

This is the OAuth login **core** plugin. A provider plugin must still register a driver through `oauth_providers` and supply its Socialite credentials. Installing the core alone does not add a login button. Existing site OAuth authorization and launcher tokens use Laravel Passport independently.

The distributable includes only the additional Socialite, SocialiteProviders Manager and OAuth 1 client packages selected against the site's production lockfile. Laravel, Symfony, Guzzle, Carbon and shared polyfills are provided by the host application. The plugin's small `vendor/autoload.php` registers only its own dependency namespaces **after** the application's loader. Do not replace it with the old 2021 vendor directory or a separately generated full Composer autoloader.

`dependencies.lock.json` records exact dependency versions and source references. `tools/build-dependencies.py` packages these dependencies from an isolated Composer installation, verifies that all production packages remain unchanged, and refuses additional/unreviewed dependency namespaces. It never runs Composer in the production webroot.

Callbacks retain Socialite's state checks, regenerate the local session, reject disabled accounts, and preserve a same-origin intended destination. Missing login sources, canceled consent, expired state and upstream failures return controlled HTTP errors without exposing provider credentials.

Email matching requires verified ownership. Google-compatible `email_verified` / `verified_email` boolean claims and Socialite's verified GitHub primary email are supported. Other provider plugins can implement the `oauth_email_verified` filter with arguments `(verified, driver, remoteUser, provider)`; return `true` only after verifying ownership of that exact email. Never trust a callback query parameter or a provider's generic profile verification badge.

New accounts respect the site's registration restrictions, including email-domain and invitation-code filters. When the required registration information is unavailable in the OAuth flow, users must complete normal site registration first. Existing accounts do not run registration filters. No production account or real provider secret is used in the automated checks.
