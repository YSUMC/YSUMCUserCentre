<?php

namespace Tests\Compatibility;

use App\Models\Texture;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class UploadTest extends TestCase
{
    public function testTextureUploadStoragePreviewAvatarAndDeletion(): void
    {
        Storage::fake('textures');
        $user = $this->makeUser();
        $user->score = 10000;
        $user->save();
        $this->actingAs($user);
        $this->get('/skinlib/upload')->assertOk();
        $upload = $this->post('/texture', [
            'file' => UploadedFile::fake()->image('skin.png', 64, 64), 'name' => 'PHP85 Test Skin', 'type' => 'steve', 'public' => '1',
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('code', 0);
        $texture = Texture::findOrFail($upload->json('data.tid'));
        Storage::disk('textures')->assertExists($texture->hash);
        $this->get('/textures/'.$texture->hash)->assertOk()->assertHeader('Content-Type', 'image/png');
        foreach (['/preview/'.$texture->tid.'?png', '/avatar/'.$texture->tid.'?png', '/avatar/'.$texture->tid.'?3d&png'] as $path) {
            $response = $this->get($path)->assertOk();
            $this->assertStringStartsWith("\x89PNG\r\n\x1a\n", $response->getContent(), $path);
        }
        $this->deleteJson('/texture/'.$texture->tid)->assertOk()->assertJsonPath('code', 0);
        Storage::disk('textures')->assertMissing($texture->hash);
    }

    public function testAdminPluginZipCanBeExtractedAndOrdinaryUserIsDenied(): void
    {
        $name = 'php85-test-'.bin2hex(random_bytes(5));
        $file = tempnam(sys_get_temp_dir(), 'php85zip');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);
        $zip->addFromString($name.'/LICENSE', 'PHP 8.5 isolated upload fixture');
        $zip->close();
        $destination = app('plugins')->getPluginsDirs()->first().'/'.$name;
        try {
            $this->actingAs($this->makeUser());
            $this->post('/admin/plugins/upload', ['file' => new UploadedFile($file, 'fixture.zip', 'application/zip', null, true)])->assertForbidden();
            $this->assertDirectoryDoesNotExist($destination);
            $this->actingAs($this->makeUser(2));
            $this->post('/admin/plugins/upload', ['file' => new UploadedFile($file, 'fixture.zip', 'application/zip', null, true)], ['Accept' => 'application/json'])
                ->assertOk()->assertJsonPath('code', 0);
            $this->assertSame('PHP 8.5 isolated upload fixture', file_get_contents($destination.'/LICENSE'));
        } finally {
            File::deleteDirectory($destination);
            @unlink($file);
        }
    }
}
