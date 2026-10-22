<?php

namespace Tests\Interop;

use SITMinecraft\Launcher\Manifest\PackMetadata;
use SITMinecraft\Launcher\Manifest\PackStore;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Database\LauncherSchema;
use Illuminate\Support\Facades\DB;

final class IngestTest extends \Tests\Security\TestCase
{
    private function archive(array $files): string
    {
        $path = storage_path('pack-'.bin2hex(random_bytes(4)).'.zip');
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path, \ZipArchive::CREATE));
        foreach ($files as $name => $contents) $zip->addFromString($name, is_array($contents) ? json_encode($contents) : $contents);
        $zip->close();
        return $path;
    }

    private function index(array $deps = ['minecraft' => '26.2', 'fabric-loader' => '0.19.5']): array
    {
        return ['formatVersion' => 1, 'game' => 'minecraft', 'dependencies' => $deps, 'files' => []];
    }

    public function testMrpackMetadataAndBothDigestsAreDerivedWithoutRewriting(): void
    {
        $path = $this->archive(['modrinth.index.json' => $this->index()]);
        $before = hash_file('sha512', $path);
        $disk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $disk->expects($this->once())->method('writeStream')->willReturn(true);
        $file = new \Illuminate\Http\UploadedFile($path, 'test.mrpack', null, null, true);
        $stored = (new PackStore($disk))->put($file, 'survival', 3);
        $this->assertSame(['game_version' => '26.2', 'loader' => 'fabric', 'loader_version' => '0.19.5'], $stored['metadata']);
        $this->assertSame($before, $stored['sha512']);
        $this->assertSame($before, hash_file('sha512', $path));
        $this->assertSame(sha1_file($path), $stored['sha1']);
    }

    public function testMultiMcWrappedInstanceIsParsed(): void
    {
        $path = $this->archive(['MyInstance/instance.cfg' => 'InstanceType=OneSix',
            'MyInstance/mmc-pack.json' => ['components' => [['uid' => 'net.minecraft', 'version' => '1.21.1'], ['uid' => 'net.neoforged', 'version' => '21.1.5']]]]);
        $this->assertSame(['game_version' => '1.21.1', 'loader' => 'neoforge', 'loader_version' => '21.1.5'], (new PackMetadata)->read($path, 'multimc'));
    }

    public function testUnknownOrConflictingLoadersFailClosed(): void
    {
        $path = $this->archive(['modrinth.index.json' => $this->index(['minecraft' => '26.2', 'fabirc-loader' => '1'])]);
        $this->expectException(\RuntimeException::class);
        (new PackMetadata)->read($path, 'mrpack');
    }

    public function testTraversalIsRejectedEvenWithoutExtraction(): void
    {
        $path = $this->archive(['modrinth.index.json' => $this->index(), '../outside.txt' => 'danger']);
        $this->expectException(\RuntimeException::class);
        (new PackMetadata)->read($path, 'mrpack');
    }

    public function testOversizedMetadataIsRejected(): void
    {
        $path = $this->archive(['modrinth.index.json' => str_repeat('a', 1048577)]);
        $this->expectException(\RuntimeException::class);
        (new PackMetadata)->read($path, 'mrpack');
    }

    public function testCurseForgePlatformOnlyFilesAreRejected(): void
    {
        $path = $this->archive(['manifest.json' => ['manifestType' => 'minecraftModpack', 'manifestVersion' => 1,
            'minecraft' => ['version' => '1.20.1', 'modLoaders' => [['id' => 'forge-47.1.0']]], 'files' => [['projectID' => 1, 'fileID' => 2]]]]);
        $this->expectExceptionMessage('upload-unresolved-files');
        (new PackMetadata)->read($path, 'curseforge-zip');
    }

    public function testVanillaOverridesAndRawKeepManualVersion(): void
    {
        $path = $this->archive(['config/options.txt' => 'foo=bar']);
        $this->assertSame([], (new PackMetadata)->read($path, 'server-zip'));
        $this->assertSame([], (new PackMetadata)->read($path, 'raw'));
    }

    public function testRevisionCannotBeOverwrittenOrActivatedBackwards(): void
    {
        $repository = new LauncherRepository;
        DB::table(LauncherSchema::INSTANCES)->insert(['instance_id' => 'x', 'name' => 'Test', 'game_version' => '26.2', 'revision' => 1]);
        $values = ['sha1' => str_repeat('a', 40), 'size' => 100];
        $repository->savePack('x', 2, $values + ['game_version' => '1.20.1', 'loader' => 'forge', 'loader_version' => '47.1.0']);
        $repository->setRevision('x', 2);
        $repository->savePack('x', 3, $values);
        $repository->setRevision('x', 3);
        $repository->setRevision('x', 2);
        $this->assertSame(3, (int) $repository->row('x')['revision']);
        $this->assertSame(4, $repository->republishPack('x', 2));
        $this->assertSame('1.20.1', $repository->row('x')['game_version']);
        $this->assertSame('forge', $repository->row('x')['loader']);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $repository->savePack('x', 4, ['sha1' => str_repeat('b', 40), 'size' => 200]);
    }
}
