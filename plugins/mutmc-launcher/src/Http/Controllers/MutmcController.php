<?php

namespace MUTMC\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\RedirectResponse;
use MUTMC\Launcher\Manifest\LauncherRepository;
use MUTMC\Launcher\Manifest\ManifestBuilder;
use MUTMC\Launcher\Interop\InteropRules;

/** A separate catalog, backed by the same pack storage and publication rules. */
final class MutmcController
{
    public function __construct(private LauncherRepository $repository, private PackController $packs) {}

    public function manifest(Request $request): Response
    {
        [$builder, $document] = $this->document($request);
        $etag = $builder->etag($document);
        $headers = ['Content-Type' => 'application/json', 'ETag' => $etag, 'Cache-Control' => 'private, no-store', 'Vary' => 'Authorization'];

        return InteropRules::ifNoneMatch($request->header('If-None-Match'), $etag)
            ? response('', 304, $headers)
            : response($builder->encode($document), 200, $headers);
    }

    public function pack(string $instanceId, int $revision): Response|RedirectResponse
    {
        [, $document] = $this->document();
        foreach ($document['instances'] as $entry) {
            if ($entry['id'] === $instanceId && $entry['revision'] === $revision) {
                return $this->packs->deliver($instanceId, $revision, [
                    'url' => $entry['pack']['url'], 'format' => 'mrpack',
                ]);
            }
        }

        return response('{"message":"Pack not found."}', 404, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
    }

    private function document(?Request $request = null): array
    {
        $builder = new ManifestBuilder(rtrim(config('app.url'), '/'), '/api/launcher/mutmc/packs');
        $rows = array_values(array_filter($this->repository->rows('mutmc'),
            static fn ($row) => ($row['pack_format'] ?? 'mrpack') === 'mrpack'));
        $request ??= request();
        $audience = app(\MUTMC\Launcher\Access\Audience::class);
        $rows = $audience->filter($rows, $request);
        $settings = $audience->settings($this->repository->settings('mutmc'), $request, 'mutmc');
        $publishedAt = 0;
        foreach ($rows as $row) {
            $publishedAt = max($publishedAt, strtotime((string) ($row['updated_at'] ?? '')) ?: 0);
        }
        $document = $builder->build($rows, $settings['min_version'], $settings['latest_version'], $settings['force_update'], $publishedAt);
        $document['cloud_map_url'] = $settings['cloud_map_url'];

        return [$builder, $document];
    }
}
