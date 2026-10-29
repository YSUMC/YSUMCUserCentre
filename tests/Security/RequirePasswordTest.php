<?php

namespace Tests\Security;

use App\Http\Controllers\AuthController;
use App\Services\PasswordResetTokens;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use RequirePassword\RequirePassword;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RequirePasswordTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $root = dirname(__DIR__, 2);
        $loader = require $root.'/vendor/autoload.php';
        $loader->addPsr4('RequirePassword\\', $root.'/plugins/require-password/src', true);
        app('router')->match(['GET', 'POST'], '/auth/reset/{uid}', fn () => null)->name('auth.reset');
        app('router')->getRoutes()->refreshNameLookups();
    }

    private function setupLink($user): string
    {
        Auth::guard()->setUser($user);
        $request = Request::create('http://localhost/user');
        $request->setUserResolver(fn () => $user);
        $response = (new RequirePassword)->handle($request, function () { $this->fail('Empty password bypassed setup'); });
        $this->assertSame(302, $response->getStatusCode());
        $this->assertFalse(Auth::check());
        return $response->getTargetUrl();
    }

    public function testThirdPartyUserCanSetPasswordOnceUsingGeneratedLink(): void
    {
        $user = $this->user(); $user->forceFill(['password' => ''])->save();
        $url = $this->setupLink($user);
        $get = Request::create($url);
        $this->assertTrue($get->hasValidSignature(false));
        $this->assertTrue(app(PasswordResetTokens::class)->valid($user->fresh(), $get->query('token')));
        $post = Request::create($url, 'POST', ['password' => 'New-Synthetic-Password']);
        $controller = new AuthController;
        $result = $controller->handleReset(app(Dispatcher::class), $post, $user->uid);
        $this->assertSame(0, $result->getData(true)['code']);
        $this->assertTrue($user->fresh()->verifyPassword('New-Synthetic-Password'));
        try { $controller->handleReset(app(Dispatcher::class), $post, $user->uid); $this->fail('Reset link was reusable'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function testExistingPasswordPassesThroughWithoutLoggingOut(): void
    {
        $user = $this->user(); Auth::guard()->setUser($user);
        $request = Request::create('http://localhost/user'); $request->setUserResolver(fn () => $user);
        $response = (new RequirePassword)->handle($request, fn () => response('user page'));
        $this->assertSame('user page', $response->getContent());
        $this->assertTrue(Auth::check());
    }

    public function testOldSignedLinksWithoutTokenStillFailClosed(): void
    {
        $user = $this->user(); $user->forceFill(['password' => ''])->save();
        $url = URL::temporarySignedRoute('auth.reset', now()->addHour(), ['uid' => $user->uid], false);
        $request = Request::create('http://localhost'.$url);
        $this->assertTrue($request->hasValidSignature(false));
        try { (new AuthController)->reset($request, $user->uid); $this->fail('Missing token accepted'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
        $this->assertSame('', $user->fresh()->password);
    }

    public function testGeneratedLinkExpires(): void
    {
        $user = $this->user(); $user->forceFill(['password' => ''])->save();
        $url = $this->setupLink($user);
        \Illuminate\Support\Carbon::setTestNow(now()->addHours(2));
        $request = Request::create($url);
        $this->assertFalse($request->hasValidSignature(false));
        $this->assertFalse(app(PasswordResetTokens::class)->valid($user->fresh(), $request->query('token')));
    }
}
