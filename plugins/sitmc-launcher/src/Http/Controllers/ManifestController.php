<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Interop\SiteDocumentService;
use SITMinecraft\Launcher\Interop\InteropRules;

/**
 * The public endpoint the launcher polls for its instance catalog.
 *
 * The launcher downloads the document itself and never sends credentials, so the
 * endpoint is unauthenticated. It answers 304 when the caller already holds the
 * current document, and it is the only place that decides what "current" means.
 */
final class ManifestController
{
    public function __construct(private LauncherRepository $repository, private SiteDocumentService $documents) {}

    public function show(Request $request): Response
    {
        $builder = new ManifestBuilder('https://'.$this->documents->domain());
        $settings = $this->repository->settings();
        // Own-launcher publication is independent of public federation listing.
        $rows = $this->repository->rows();
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

        $document['cloud_map_url'] = $settings['cloud_map_url'];
        $etag = $builder->etag($document);

        if (InteropRules::ifNoneMatch($request->header('If-None-Match'), $etag)) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($builder->encode($document), 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
