<?php

namespace SITMinecraft\Launcher\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use SITMinecraft\Launcher\Http\PackStreamResponse;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
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
        $row = $this->repository->row($instanceId);
        if ($row !== null && ($row['distribution_brand'] ?? 'ysumc') === 'ysumc') {
            $own = (new ManifestBuilder('https://'.$this->documents->domain()))->build([$row], publishedAt: 0);
            $entry = $own['instances'][0] ?? null;
            if ($entry !== null && $entry['revision'] === $revision) {
                $published = ['artifact' => [
                    'format' => $entry['pack']['kind'], 'url' => $entry['pack']['url'],
                ]];
            }
        }
        if ($published === null) {
            foreach ($this->documents->document()['document']['packs'] as $entry) {
                if (! isset($entry['source']) && $entry['id'] === $instanceId && ($entry['revision'] ?? null) === $revision && isset($entry['artifact'])) $published = $entry;
            }
        }
        if ($published === null) return new Response('{"message":"Pack not found."}', 404, ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']);
        return $this->deliver($instanceId, $revision, $published['artifact']);
    }

    /** Called only after the owning catalog verifies publication and revision. */
    public function deliver(string $instanceId, int $revision, array $artifact): Response|RedirectResponse
    {
        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $external = trim((string) ($pack['url'] ?? ''));

        if ($external !== '') {
            return redirect()->away($artifact['url']);
        }

        $diskPath = trim((string) ($pack['disk_path'] ?? ''));
        $extension = match ($artifact['format']) { 'mrpack' => 'mrpack', 'raw' => 'bin', default => 'zip' };
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
