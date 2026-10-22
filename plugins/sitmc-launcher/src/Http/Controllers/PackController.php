<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Http\PackStreamResponse;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\PackStore;
use SITMinecraft\Launcher\Interop\SiteDocumentService;

/**
 * Serves an uploaded pack at the URL the manifest advertises.
 *
 * Rows that point at an external URL are redirected to it instead, so an
 * administrator can move large packs to object storage without changing the
 * launcher contract.
 */
final class PackController
{
    public function __construct(
        private LauncherRepository $repository,
        private PackStore $packs,
        private SiteDocumentService $documents,
    ) {}

    public function show(string $instanceId, int $revision): Response|RedirectResponse
    {
        $published = null;
        foreach ($this->documents->document()['document']['packs'] as $entry) {
            if (! isset($entry['source']) && $entry['id'] === $instanceId && ($entry['revision'] ?? null) === $revision && isset($entry['artifact'])) $published = $entry;
        }
        if ($published === null) return new Response('{"message":"Pack not found."}', 404, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $external = trim((string) ($pack['url'] ?? ''));

        if ($external !== '') {
            return redirect()->away($published['artifact']['url']);
        }

        $diskPath = trim((string) ($pack['disk_path'] ?? ''));
        $extension = match ($published['artifact']['format']) { 'mrpack' => 'mrpack', 'raw' => 'bin', default => 'zip' };
        $name = sprintf('%s-%d.%s', $instanceId, $revision, $extension);

        if ($diskPath === '') {
            abort(404);
        }

        $absolute = $this->packs->absolutePath($diskPath);

        if ($absolute !== null) {
            return new PackStreamResponse($absolute, $name);
        }

        $contents = $this->packs->contents($diskPath);

        if ($contents === null) {
            abort(404);
        }

        return response($contents, 200, [
            'Content-Type' => $extension === 'bin' ? 'application/octet-stream' : 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ]);
    }
}
