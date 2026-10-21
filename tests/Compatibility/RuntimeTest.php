<?php

namespace Tests\Compatibility;

use Blessing\Filter;
use Blessing\Rejection;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

class RuntimeTest extends TestCase
{
    public function testTargetRuntimeAndDates(): void
    {
        $this->assertSame('8.5.12', PHP_VERSION);
        $this->assertStringStartsWith('13.', app()->version());
        $this->assertInstanceOf(Carbon::class, Carbon::now());
        $this->assertInstanceOf(CarbonImmutable::class, CarbonImmutable::now());
        $this->assertNotSame('auth.login.success', trans('auth.login.success'));
    }

    public function testHookPriorityAndRejectionPayload(): void
    {
        $filter = new Filter(app());
        $filter->add('test', fn ($value) => $value.'B', 20);
        $filter->add('test', fn ($value) => $value.'A', 10);
        $this->assertSame('AB', $filter->apply('test', ''));
        $rejection = new Rejection('denied', ['nested' => ['value' => 42]]);
        $this->assertSame('denied', $rejection->getReason());
        $this->assertSame(42, $rejection->getData('nested.value'));
    }

    public function testSearchParserAndBindings(): void
    {
        $user = $this->makeUser();
        $result = \App\Models\User::usingSearchString('uid:'.$user->uid)->get();
        $this->assertSame([$user->uid], $result->pluck('uid')->all());
        $this->assertSame(0, \App\Models\User::usingSearchString('uid:'.$user->uid.' permission:2')->count());
    }

    public function testZipAndFilesystemRoundTrip(): void
    {
        Storage::fake('textures');
        $this->assertTrue(Storage::disk('textures')->put('test.txt', 'round-trip'));
        $this->assertSame('round-trip', Storage::disk('textures')->get('test.txt'));
        $path = tempnam(sys_get_temp_dir(), 'php85-zip-');
        try {
            $zip = new \ZipArchive();
            $this->assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
            $zip->addFromString('LICENSE', 'fixture-license');
            $zip->close();
            $this->assertTrue($zip->open($path));
            $this->assertSame('fixture-license', $zip->getFromName('LICENSE'));
            $zip->close();
        } finally {
            unlink($path);
        }
    }
}
