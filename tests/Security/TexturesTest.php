<?php

namespace Tests\Security;

use App\Http\Controllers\TextureController;
use App\Models\Player;
use App\Models\Texture;
use Blessing\Filter;
use Blessing\Minecraft;
use Blessing\Rejection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use SITMinecraft\Launcher\Auth\TokenVerifier;
use SITMinecraft\Launcher\Http\Controllers\SkinController;

class TexturesTest extends TestCase
{
    private function launcher($user): SkinController
    {
        Player::forceCreate(['name' => 'Test'.$user->uid, 'uid' => $user->uid, 'tid_skin' => 0, 'tid_cape' => 0]);
        app()->instance(TokenVerifier::class, new class($user->uid) {
            public function __construct(private int $uid) {}
            public function verifiedPlayer($token) { return $token === 'synthetic' ? 'Test'.$this->uid : null; }
        });
        return app(SkinController::class);
    }

    private function upload(SkinController $controller, array $query = [])
    {
        $image = imagecreatetruecolor(64, 64);
        ob_start(); imagepng($image); $png = ob_get_clean();
        $request = Request::create('/api/launcher/skins?'.http_build_query(array_replace([
            'player' => 'Test1', 'type' => 'steve', 'name' => 'Test skin', 'public' => 'true',
        ], $query)), 'POST', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer synthetic', 'CONTENT_TYPE' => 'image/png'], $png);
        return $controller->upload($request);
    }

    public function testLauncherUploadChargesScoreAndDispatchesWebsiteEvents(): void
    {
        $user = $this->user(); $controller = $this->launcher($user);
        $events = [];
        app('events')->listen('texture.uploaded', function ($texture, $file) use (&$events) { $events[] = $texture->tid; });
        $result = $this->upload($controller)->getData(true);
        $this->assertTrue($result['created']);
        $texture = Texture::findOrFail($result['texture']['tid']);
        $this->assertTrue(Storage::disk('textures')->exists($texture->hash));
        $this->assertSame(1000 - $texture->size * 2 - 5, $user->fresh()->score);
        $this->assertSame([$texture->tid], $events);
        $this->assertSame(1, $user->closet()->count());
        $this->assertFalse($this->upload($controller)->getData(true)['created']);
        $this->assertSame(1000 - $texture->size * 2 - 5, $user->fresh()->score);
        $this->assertNull(auth()->user(), 'The temporary launcher auth context must not escape the adapter.');
    }

    public function testLauncherCannotUploadWithoutEnoughScore(): void
    {
        $user = $this->user(); $user->score = 0; $user->save();
        $result = $this->upload($this->launcher($user));
        $this->assertSame(400, $result->status());
        $this->assertSame('upload_rejected', $result->getData(true)['error']);
        $this->assertSame(0, Texture::count());
        $this->assertSame(0, $user->closet()->count());
    }

    public function testLauncherRespectsUploadPluginRejection(): void
    {
        $user = $this->user(); $controller = $this->launcher($user);
        app(Filter::class)->add('can_upload_texture', fn () => new Rejection('Blocked by test policy'));
        $result = $this->upload($controller)->getData(true);
        $this->assertSame('upload_rejected', $result['error']);
        $this->assertSame('Blocked by test policy', $result['message']);
        $this->assertSame(0, Texture::count());
    }

    public function testPrivateUploadUsesPrivateStorageCost(): void
    {
        $user = $this->user();
        $result = $this->upload($this->launcher($user), ['public' => 'false'])->getData(true);
        $texture = Texture::findOrFail($result['texture']['tid']);
        $this->assertFalse($texture->public);
        $this->assertSame(1000 - $texture->size * 3 - 5, $user->fresh()->score);
    }

    public function testDuplicatePublicTextureUsesClosetAccounting(): void
    {
        $owner = $this->user(); $this->upload($this->launcher($owner));
        $other = $this->user(); $controller = $this->launcher($other);
        $events = [];
        app('events')->listen('closet.added', function ($texture) use (&$events) { $events[] = $texture->tid; });
        $result = $this->upload($controller, ['player' => 'Test2'])->getData(true);
        $this->assertFalse($result['created']);
        $this->assertSame(995, $other->fresh()->score);
        $this->assertSame(1, $other->closet()->count());
        $this->assertSame([$result['texture']['tid']], $events);
    }

    public function testDuplicateUploadCannotBypassClosetPolicyOrScore(): void
    {
        $owner = $this->user(); $this->upload($this->launcher($owner));
        $other = $this->user(); $other->score = 0; $other->save();
        $controller = $this->launcher($other);
        $this->assertSame('closet_rejected', $this->upload($controller, ['player' => 'Test2'])->getData(true)['error']);
        $this->assertSame(0, $other->closet()->count());
        $other->score = 100; $other->save();
        app(Filter::class)->add('can_add_closet_item', fn () => new Rejection('Closet policy'));
        $this->assertSame('Closet policy', $this->upload($controller, ['player' => 'Test2'])->getData(true)['message']);
        $this->assertSame(100, $other->fresh()->score);
    }

    public function testNormalAvatarStillRendersAtTheMaximumAllowedSize(): void
    {
        $this->app->register(\Intervention\Image\ImageServiceProvider::class);
        \Illuminate\Foundation\AliasLoader::getInstance()->alias('Image', \Intervention\Image\Facades\Image::class);
        $user = $this->user(); $result = $this->upload($this->launcher($user))->getData(true);
        $response = (new TextureController)->avatarByTexture(app(Minecraft::class), Request::create('/avatar', 'GET', ['size' => 512, 'png' => 1]), $result['texture']['tid']);
        $size = getimagesizefromstring($response->getContent());
        $this->assertSame([512, 512], [$size[0], $size[1]]);
    }

    public function testLauncherUsesTheSameDelimitedNameRegexAsWebsite(): void
    {
        $user = $this->user(); $controller = $this->launcher($user);
        option(['texture_name_regexp' => '/^Allowed$/u']);
        $this->assertTrue($this->upload($controller, ['name' => 'Allowed'])->getData(true)['created']);
        $this->expectException(ValidationException::class);
        $this->upload($controller, ['name' => 'Rejected']);
    }

    public static function invalidDimensions(): array
    {
        return [['0'], ['-1'], ['513'], ['100000'], ['no-number'], [['100']]];
    }

    #[DataProvider('invalidDimensions')]
    public function testAvatarDimensionsAreRejectedBeforeRendering(mixed $size): void
    {
        $request = Request::create('/avatar/0', 'GET', ['size' => $size]);
        $this->expectException(ValidationException::class);
        (new TextureController)->avatarByTexture(app(Minecraft::class), $request, 0);
    }
}
