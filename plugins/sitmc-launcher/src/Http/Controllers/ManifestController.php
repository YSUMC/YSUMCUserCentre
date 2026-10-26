<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Interop\InteropRules;

/** Account-aware home catalog. Public federation remains a separate projection. */
final class ManifestController
{
    public function __construct(private LauncherRepository $repository, private SiteDocumentService $documents) {}

    public function show(Request $request): Response
    {
        $builder = new ManifestBuilder('https://'.$this->documents->domain());
        $settings = $this->repository->settings();
        // Own-launcher publication is independent of public federation listing.
        $rows = app(\SITMinecraft\Launcher\Access\Audience::class)->filter($this->repository->rows(), $request);
        $settings = app(\SITMinecraft\Launcher\Access\Audience::class)->settings($settings, $request);
        $publishedAt = 0;
        foreach ($rows as $row) {
            $publishedAt = max($publishedAt, strtotime((string) ($row['updated_at'] ?? '')) ?: 0);
        }

        $document = $builder->build(
            $rows,
            $settings['min_version'],
            $settings['latest_version'],
            $settings['force_update'],
            $publishedAt
        );

        // The authenticated projection also includes server-only instances. It is
        // never cached or re-exported through public federation endpoints.
        $document['catalog'] = $this->documents->audienceDocument($request);
        $document['cloud_map_url'] = $settings['cloud_map_url'];
        $etag = $builder->etag($document);

        if (InteropRules::ifNoneMatch($request->header('If-None-Match'), $etag)) {
            return response('', 304, ['ETag' => $etag] + \SITMinecraft\Launcher\Access\Audience::headers());
        }

        return response($builder->encode($document), 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'private, no-store',
            'Vary' => 'Authorization',
        ]);
    }
}
