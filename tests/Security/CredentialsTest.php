<?php

namespace Tests\Security;

use App\Http\Controllers\AuthController;
use App\Services\PasswordResetTokens;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use LittleSkin\YggdrasilConnect\Models\AccessToken;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CredentialsTest extends TestCase
{
    private function seedTokens($user): void
    {
        foreach ([1, 2] as $client) {
            DB::table('oauth_access_tokens')->insert(['id' => "access-$user->uid-$client", 'user_id' => $user->uid,
                'client_id' => $client, 'revoked' => false, 'expires_at' => now()->subDay()]);
            DB::table('oauth_refresh_tokens')->insert(['id' => "refresh-$user->uid-$client", 'access_token_id' => "access-$user->uid-$client", 'revoked' => false]);
        }
        DB::table('oauth_auth_codes')->insert(['id' => "code-$user->uid", 'user_id' => $user->uid, 'revoked' => true]);
        DB::table('yggc_grants')->insert(['id' => "code-$user->uid"]);
    }

    public function testPasswordChangesRevokeAllClientsIncludingExpiredTokens(): void
    {
        $user = $this->user(); $other = $this->user();
        $this->seedTokens($user); $this->seedTokens($other);
        $reset = app(PasswordResetTokens::class)->issue($user);
        $user->changePassword('New-Test-Password');
        $this->assertTrue($user->verifyPassword('New-Test-Password'));
        $this->assertSame(0, DB::table('oauth_access_tokens')->where('user_id', $user->uid)->where('revoked', false)->count());
        $this->assertSame(2, DB::table('oauth_refresh_tokens')->where('revoked', true)->count());
        $this->assertSame(2, DB::table('oauth_access_tokens')->where('user_id', $other->uid)->where('revoked', false)->count());
        $this->assertFalse(DB::table('oauth_auth_codes')->where('id', "code-$user->uid")->exists());
        $this->assertFalse(DB::table('yggc_grants')->where('id', "code-$user->uid")->exists());
        $this->assertTrue(DB::table('yggc_grants')->where('id', "code-$other->uid")->exists());
        $this->assertFalse(app(PasswordResetTokens::class)->valid($user, $reset));
        $this->assertNotEmpty($user->remember_token);
    }

    public function testSignoutRevokesEveryClientAndProfileEmailChangesDoToo(): void
    {
        $user = $this->user(); $other = $this->user();
        $this->seedTokens($user); $this->seedTokens($other);
        AccessToken::revokeAllForUser($user);
        $this->assertSame(2, DB::table('oauth_refresh_tokens')->where('revoked', true)->count());
        $other->email = 'changed@example.invalid'; $other->save();
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
    }

    public function testResetTokensExpireReplaceAndCannotBeReused(): void
    {
        $user = $this->user(); $tokens = app(PasswordResetTokens::class);
        $old = $tokens->issue($user); $new = $tokens->issue($user);
        $this->assertFalse($tokens->valid($user, $old));
        $this->assertTrue($tokens->valid($user, $new));
        $this->assertFalse($tokens->valid($user, ['invalid']));
        $this->assertFalse($tokens->consume($user, str_repeat('x', 64)));
        $this->assertTrue($tokens->consume($user, $new));
        $this->assertFalse($tokens->consume($user, $new));
        $expires = $tokens->issue($user);
        \Illuminate\Support\Carbon::setTestNow(now()->addHours(2));
        $this->assertFalse($tokens->valid($user, $expires));
    }

    public function testSignedResetRequestSucceedsOnceAndRevokesTokens(): void
    {
        $user = $this->user(); $this->seedTokens($user);
        app('router')->post('/auth/reset/{uid}', fn () => null)->name('auth.reset');
        app('router')->getRoutes()->refreshNameLookups();
        $url = URL::temporarySignedRoute('auth.reset', now()->addHour(), [
            'uid' => $user->uid, 'token' => app(PasswordResetTokens::class)->issue($user),
        ], false);
        $request = Request::create('http://localhost'.$url, 'POST', ['password' => 'Reset-Test-Password']);
        $controller = new AuthController;
        $response = $controller->handleReset(app(Dispatcher::class), $request, $user->uid);
        $this->assertSame(0, $response->getData(true)['code']);
        $this->assertTrue($user->fresh()->verifyPassword('Reset-Test-Password'));
        $this->assertSame(0, DB::table('oauth_refresh_tokens')->where('revoked', false)->count());
        $this->expectException(HttpException::class);
        $controller->handleReset(app(Dispatcher::class), $request, $user->uid);
    }
}
