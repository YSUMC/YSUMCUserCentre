# require-password

Redirect users with empty password to reset page.

## How?

1. Enable this plugin.
2. Login with third-party providers.
3. Provider plugin creates a user without password.
4. This plugin forces user to log out and reset password.

YSUMC compatibility: the signed reset URL also carries a one-time token issued by
`App\Services\PasswordResetTokens`. Both the signature and token are required by
the reset controller; the token expires after one hour and is consumed on reset.
After upgrading, sign in again with the third-party provider to obtain a new link.
