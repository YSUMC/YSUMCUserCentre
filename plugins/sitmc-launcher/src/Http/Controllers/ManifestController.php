<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Interop\SiteDocumentService;

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
        $canonical = $this->documents->document()['document'];
        $builder = new ManifestBuilder('https://'.$canonical['site']['domain']);
        $settings = $this->repository->settings();

        $allowed = [];
        foreach ($canonical['packs'] as $entry) {
            if (! isset($entry['source']) && ($entry['artifact']['format'] ?? null) === 'mrpack' && isset($entry['artifact']['sha1'])) $allowed[$entry['id']] = $entry;
        }

        $rows = [];
        foreach ($this->repository->rows() as $row) {
            $entry = $allowed[$row['id']] ?? null;
            if ($entry === null) continue;
            $rows[] = array_replace($row, ['game_version' => $entry['gameVersion'], 'loader' => $entry['loader'],
                'loader_version' => $entry['loaderVersion'] ?? null, 'pack_url' => $entry['artifact']['url'],
                'pack_sha1' => $entry['artifact']['sha1'], 'pack_size' => $entry['artifact']['size']]);
        }

        $document = $builder->build(
            $rows,
            $settings['min_version'],
            $settings['latest_version'],
            $settings['force_update'],
            strtotime($canonical['updatedAt'])
        );

        $document['cloud_map_url'] = $settings['cloud_map_url'];
        $etag = $builder->etag($document);

        if (trim((string) $request->header('If-None-Match')) === $etag) {
            return response('', 304, ['ETag' => $etag]);
        }

        return response($builder->encode($document), 200, [
            'Content-Type' => 'application/json',
            'ETag' => $etag,
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }
}
