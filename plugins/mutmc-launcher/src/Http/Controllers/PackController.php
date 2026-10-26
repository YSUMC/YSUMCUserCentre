<?php

namespace MUTMC\Launcher\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use MUTMC\Launcher\Http\PackStreamResponse;
use MUTMC\Launcher\Manifest\LauncherRepository;
use MUTMC\Launcher\Manifest\ManifestBuilder;
use MUTMC\Launcher\Manifest\PackStore;

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
    ) {}

    /** Called only after the owning catalog verifies publication and revision. */
    public function deliver(string $instanceId, int $revision, array $artifact): Response|RedirectResponse
    {
        $row = $this->repository->row($instanceId);
        $identity = app(\MUTMC\Launcher\Access\Audience::class)->identity(request());
        abort_unless($row !== null && \MUTMC\Launcher\Access\Audience::permits($row, $identity), 404);
        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $external = trim((string) ($pack['url'] ?? ''));

        if ($external !== '') {
            return redirect()->away($external)->withHeaders(\MUTMC\Launcher\Access\Audience::headers());
        }

        $diskPath = trim((string) ($pack['disk_path'] ?? ''));
        $extension = match ($artifact['format']) { 'mrpack' => 'mrpack', 'raw' => 'bin', default => 'zip' };
        $name = sprintf('%s-%d.%s', $instanceId, $revision, $extension);

        if ($diskPath === '') {
            abort(404);
        }

        $absolute = $this->packs->absolutePath($diskPath);

        if ($absolute !== null) {
            return (new PackStreamResponse($absolute, $name))->withHeaders(\MUTMC\Launcher\Access\Audience::headers());
        }

        $contents = $this->packs->contents($diskPath);

        if ($contents === null) {
            abort(404);
        }

        return response($contents, 200, [
            'Content-Type' => $extension === 'bin' ? 'application/octet-stream' : 'application/zip',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
        ] + \MUTMC\Launcher\Access\Audience::headers());
    }
}
