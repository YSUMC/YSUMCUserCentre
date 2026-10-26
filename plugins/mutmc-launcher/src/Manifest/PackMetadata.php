<?php

namespace MUTMC\Launcher\Manifest;

use RuntimeException;
use MUTMC\Launcher\Interop\InteropRules;
use MUTMC\Launcher\Interop\PublicAddress;
use ZipArchive;

/** Bounded, read-only ingest: no extraction and no rewriting of the artifact. */
final class PackMetadata
{
    public function read(string $path, string $format): array
    {
        if (! in_array($format, InteropRules::FORMATS, true)) throw new RuntimeException('upload-invalid-metadata');
        if ($format === 'raw') return [];
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) throw new RuntimeException('upload-not-zip');
        try {
            $names = $this->inspect($zip);
            if ($format === 'server-zip') return [];
            $game = null; $loaders = [];
            if ($format === 'mrpack') {
                $index = $this->json($zip, 'modrinth.index.json');
                if (($index['formatVersion'] ?? null) !== 1 || ($index['game'] ?? null) !== 'minecraft' || ! is_array($index['files'] ?? null)) throw new RuntimeException('upload-invalid-metadata');
                foreach ($index['files'] as $file) {
                    if (! is_array($file) || ! $this->safePath($file['path'] ?? null)) throw new RuntimeException('upload-invalid-metadata');
                }
                $dependencies = $index['dependencies'] ?? [];
                if (! is_array($dependencies)) throw new RuntimeException('upload-invalid-metadata');
                $game = $dependencies['minecraft'] ?? null;
                foreach ($dependencies as $name => $version) {
                    if ($name === 'minecraft') continue;
                    $loader = match ($name) { 'fabric-loader' => 'fabric', 'quilt-loader' => 'quilt', 'forge' => 'forge', 'neoforge' => 'neoforge', default => 'unknown' };
                    $loaders[$loader] = $version;
                }
            } elseif ($format === 'multimc') {
                $matches = array_values(array_filter($names, static fn ($name) => basename($name) === 'mmc-pack.json'));
                if (count($matches) !== 1) throw new RuntimeException('upload-invalid-metadata');
                $this->bytes($zip, substr($matches[0], 0, -13).'instance.cfg');
                $index = $this->json($zip, $matches[0]);
                if (! is_array($index['components'] ?? null)) throw new RuntimeException('upload-invalid-metadata');
                foreach ($index['components'] as $component) {
                    if (! is_array($component)) throw new RuntimeException('upload-invalid-metadata');
                    $name = $component['uid'] ?? '';
                    if ($name === 'net.minecraft') { $game = $component['version'] ?? null; continue; }
                    if (in_array($name, ['org.lwjgl', 'org.lwjgl3'], true)) continue;
                    $loader = match ($name) { 'net.fabricmc.fabric-loader' => 'fabric', 'org.quiltmc.quilt-loader' => 'quilt', 'net.minecraftforge' => 'forge', 'net.neoforged' => 'neoforge', default => 'unknown' };
                    $loaders[$loader] = $component['version'] ?? null;
                }
            } else {
                $index = $this->json($zip, 'manifest.json');
                if (($index['manifestType'] ?? null) !== 'minecraftModpack' || ($index['manifestVersion'] ?? null) !== 1 || ! is_array($index['files'] ?? null)) throw new RuntimeException('upload-invalid-metadata');
                $game = $index['minecraft']['version'] ?? null;
                if (! is_array($index['minecraft']['modLoaders'] ?? [])) throw new RuntimeException('upload-invalid-metadata');
                foreach ($index['minecraft']['modLoaders'] ?? [] as $loader) {
                    if (! is_array($loader)) throw new RuntimeException('upload-invalid-metadata');
                    if (! preg_match('/^(fabric|forge|neoforge|quilt)-(.+)$/D', $loader['id'] ?? '', $match)) throw new RuntimeException('upload-invalid-metadata');
                    $loaders[$match[1]] = $match[2];
                }
                // Unresolved platform-only IDs cannot be installed from this neutral catalog.
                foreach ($index['files'] as $file) {
                    if (! is_string($file['downloadUrl'] ?? null)) throw new RuntimeException('upload-unresolved-files');
                    try { (new PublicAddress)->checkUrl($file['downloadUrl']); }
                    catch (\InvalidArgumentException) { throw new RuntimeException('upload-unresolved-files'); }
                }
            }
            if (! is_string($game) || $game === '' || strlen($game) > 64 || count($loaders) > 1) throw new RuntimeException('upload-invalid-metadata');
            $loader = array_key_first($loaders) ?? 'vanilla';
            $version = $loaders[$loader] ?? null;
            if (! in_array($loader, InteropRules::LOADERS, true) || ($loader !== 'vanilla' && (! is_string($version) || $version === '' || strlen($version) > 64))) throw new RuntimeException('upload-invalid-metadata');
            return ['game_version' => $game, 'loader' => $loader, 'loader_version' => $version];
        } catch (\JsonException|\TypeError $e) {
            throw new RuntimeException('upload-invalid-metadata', 0, $e);
        } finally {
            $zip->close();
        }
    }

    private function inspect(ZipArchive $zip): array
    {
        $names = []; $size = 0;
        if ($zip->numFiles > 10000) throw new RuntimeException('upload-invalid-metadata');
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i); $name = $stat['name']; $size += $stat['size'];
            $zip->getExternalAttributesIndex($i, $system, $attributes);
            if (isset($names[$name]) || ! $this->safePath($name) ||
                (($attributes >> 16) & 0170000) === 0120000 || ($stat['encryption_method'] ?? 0) !== 0 ||
                $size > 2147483648 || $stat['size'] > 536870912 || $stat['size'] > max(1048576, $stat['comp_size'] * 1000)) throw new RuntimeException('upload-invalid-metadata');
            $names[$name] = true;
        }
        return array_keys($names);
    }

    private function safePath(mixed $name): bool
    {
        return is_string($name) && $name !== '' && ! preg_match('~(?:^/|\\\\|:|(?:^|/)\.\.(?:/|$)|[\x00-\x1f])~', $name);
    }

    private function bytes(ZipArchive $zip, string $name): string
    {
        $stat = $zip->statName($name);
        if ($stat === false || $stat['size'] > 1048576) throw new RuntimeException('upload-invalid-metadata');
        $value = $zip->getFromName($name, 1048577);
        if ($value === false || strlen($value) !== $stat['size']) throw new RuntimeException('upload-invalid-metadata');
        return $value;
    }

    private function json(ZipArchive $zip, string $name): array
    {
        $value = json_decode($this->bytes($zip, $name), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($value)) throw new RuntimeException('upload-invalid-metadata');
        return $value;
    }
}
