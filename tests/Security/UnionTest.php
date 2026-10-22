<?php

namespace Tests\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use LittleSkin\YggdrasilConnect\Controllers\UnionProfileController;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UnionTest extends TestCase
{
    public static function operations(): array
    {
        return [['bind', 'uuid'], ['unbind', 'uuid'], ['bindTo', 'uuid'], ['requestRemapUUID', 'me']];
    }

    #[DataProvider('operations')]
    public function testForeignProfileIsRejectedBeforeAnyUpstreamRequest(string $method, string $field): void
    {
        $owner = $this->user(); $attacker = $this->user();
        $pid = DB::table('players')->insertGetId(['uid' => $owner->uid, 'name' => 'OwnedPlayer'], 'pid');
        DB::table('uuid')->insert(['pid' => $pid, 'name' => 'OwnedPlayer', 'uuid' => 'owned-uuid']);
        $request = Request::create('/union', 'POST', [$field => 'owned-uuid', 'target' => 'remote', 'token' => 'synthetic']);
        $request->setUserResolver(fn () => $attacker);
        Http::fake(['alliance.invalid/*' => Http::response(['token' => 'synthetic'])]);
        try {
            (new UnionProfileController)->$method($request);
            $this->fail('Another user profile was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        Http::assertNothingSent();
        $request->setUserResolver(fn () => $owner);
        (new UnionProfileController)->$method($request);
        Http::assertSentCount(1);
    }

    public function testUnionPageWorksWithPromisesTwoAndAnEmptyAccount(): void
    {
        $user = $this->user();
        app('auth')->setUser($user);
        app('view')->addNamespace('LittleSkin\\YggdrasilConnect', base_path('plugins/yggdrasil-connect/views'));
        // Twig is unnecessary for testing the controller's promise collection.
        app('view')->addExtension('twig', 'file');
        $view = (new UnionProfileController)->render(Request::create('/union'));
        $this->assertSame([], $view->getData()['profiles']);
    }
}
