<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;
use SITMinecraft\Launcher\Manifest\LauncherRepository;
use SITMinecraft\Launcher\Manifest\ManifestBuilder;
use SITMinecraft\Launcher\Manifest\ManifestRules;
use SITMinecraft\Launcher\Manifest\PackStore;
use SITMinecraft\Launcher\Manifest\ExternalPackInspector;

/**
 * Admin screens for the launcher catalog.
 *
 * Publishing is deliberately revision based: editing an instance only changes
 * what clients display, while a new pack upload (or an explicit activation of an
 * older revision) is what forces every client to reinstall.
 */
final class InstanceController
{
    use ScopesDistribution;

    public function __construct(
        private LauncherRepository $repository,
        private PackStore $packs,
    ) {}

    public function index(Request $request): View
    {
        $brand = $this->distributionBrand($request);
        $builder = new ManifestBuilder($request->root(), $brand === 'mutmc' ? '/api/launcher/mutmc/packs' : '/api/launcher/packs');

        $instances = [];

        foreach ($this->repository->rows($brand) as $row) {
            $row['problem'] = ManifestRules::publishProblem($row, $builder->packUrlFor($row));
            $row['pack_url_resolved'] = $builder->packUrlFor($row);
            $instances[] = $row;
        }

        return view('sitmc-launcher::admin.instances', [
            'instances' => $instances,
            'settings' => $this->repository->settings($brand),
            'distributionBrand' => $brand,
            'limits' => $this->uploadLimits(),
            'manifestPath' => $brand === 'mutmc' ? '/api/launcher/mutmc/manifest' : '/api/launcher/manifest',
            'status' => session('sitmc_launcher_status'),
        ] + $this->distributionViewData($request));
    }

    public function create(Request $request): View|RedirectResponse
    {
        if ($request->query('brand') === 'mutmc' && $this->distributionBrand($request) !== 'mutmc') {
            return redirect('admin/launcher/mutmc/instances/create');
        }

        return view('sitmc-launcher::admin.instance', [
            'instance' => null,
            'distributionBrand' => $this->distributionBrand($request),
            'packs' => [],
            'limits' => $this->uploadLimits(),
            'status' => null,
        ] + $this->distributionViewData($request));
    }

    public function store(Request $request): RedirectResponse
    {
        $values = $this->validated($request);

        $this->repository->saveInstance($values + [
            'revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $values['instance_id'])
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.created'));
    }

    public function edit(Request $request, string $instanceId): View
    {
        $row = $this->repository->row($instanceId);

        $this->requireDistribution($request, $row);

        $builder = new ManifestBuilder($request->root(), $row['distribution_brand'] === 'mutmc' ? '/api/launcher/mutmc/packs' : '/api/launcher/packs');
        $row['problem'] = ManifestRules::publishProblem($row, $builder->packUrlFor($row));
        $row['pack_url_resolved'] = $builder->packUrlFor($row);

        return view('sitmc-launcher::admin.instance', [
            'instance' => $row,
            'distributionBrand' => $row['distribution_brand'],
            'packs' => $this->repository->packs($instanceId),
            'limits' => $this->uploadLimits(),
            'status' => session('sitmc_launcher_status'),
            'externalInput' => $request->session()->getOldInput(),
        ] + $this->distributionViewData($request));
    }

    public function update(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);

        $values = $this->validated($request, $instanceId);
        $values['updated_at'] = now();

        $this->repository->saveInstance($values);

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.saved'));
    }

    public function destroy(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);
        foreach ($this->repository->packs($instanceId) as $pack) {
            $this->packs->forget($pack['disk_path'] ?? null);
        }

        $this->repository->deleteInstance($instanceId);

        return redirect()
            ->route($this->adminRouteName($request, 'index'))
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.deleted'));
    }

    public function storePack(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);

        $request->validate(
            ['pack' => ['required', 'file', 'max:'.$this->maxUploadKilobytes()], 'format' => ['nullable', ($this->repository->row($instanceId)['distribution_brand'] === 'mutmc' ? 'in:mrpack' : 'in:mrpack,multimc,curseforge-zip,server-zip,raw')]],
            ['pack.max' => trans('sitmc-launcher::launcher.packs.too-large')]
        );

        $revision = $this->nextRevision($instanceId);

        try {
            $stored = $this->packs->put($request->file('pack'), $instanceId, $revision, $request->input('format') ?: 'mrpack');
        } catch (RuntimeException $exception) {
            return back()->withErrors([
                'pack' => trans('sitmc-launcher::launcher.packs.error.'.$exception->getMessage()),
            ]);
        }

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($instanceId, $revision, $stored): void {
                $this->repository->savePack($instanceId, $revision, [
                    'disk_path' => $stored['disk_path'],
                    'original_name' => $stored['original_name'],
                    'sha1' => $stored['sha1'],
                    'sha512' => $stored['sha512'],
                    'format' => $stored['format'],
                    'size' => $stored['size'],
                    'updated_at' => now(),
                    'created_at' => now(),
                ] + $stored['metadata']);

                $this->repository->setRevision($instanceId, $revision);
                if ($stored['metadata'] !== []) $this->repository->saveInstance(['instance_id' => $instanceId] + $stored['metadata']);
            });
        } catch (\Throwable $e) {
            if (! $this->repository->hasPackFileReference($stored['disk_path'])) $this->packs->forget($stored['disk_path']);
            throw $e;
        }

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-uploaded', [
                'revision' => $revision,
            ]));
    }

    public function storeExternalPack(Request $request, string $instanceId): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);

        $values = $request->validate([
            'url' => ['required', 'string', 'max:512'],
            'format' => ['nullable', ($this->repository->row($instanceId)['distribution_brand'] === 'mutmc' ? 'in:mrpack' : 'in:mrpack,multimc,curseforge-zip,server-zip,raw')],
        ]);

        $values['url'] = trim($values['url']);
        $problem = ManifestRules::packUrlProblem($values['url']);
        if ($problem !== null) {
            return back()->withInput($values)->withErrors([
                'url' => trans('sitmc-launcher::launcher.problem.'.$problem),
            ]);
        }

        try {
            // Download once and compute authoritative values. Old forms may
            // still submit manual hashes; those values are deliberately ignored.
            $metadata = app(ExternalPackInspector::class)->inspect($values['url'], $values['format'] ?? 'mrpack');
        } catch (\InvalidArgumentException) {
            return back()->withInput($values)->withErrors(['url' => trans('sitmc-launcher::launcher.packs.error.remote-url')]);
        } catch (RuntimeException $e) {
            return back()->withInput($values)->withErrors(['url' => trans('sitmc-launcher::launcher.packs.error.'.$e->getMessage())]);
        }

        // No database writes or revision changes until the entire file is read.
        $revision = $this->nextRevision($instanceId);

        \Illuminate\Support\Facades\DB::transaction(function () use ($instanceId, $revision, $values, $metadata): void {
            $this->repository->savePack($instanceId, $revision, [
                'url' => $values['url'],
                'sha1' => $metadata['sha1'],
                'sha512' => $metadata['sha512'],
                'format' => $values['format'] ?? 'mrpack',
                'size' => $metadata['size'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->repository->setRevision($instanceId, $revision);
        });

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-uploaded', [
                'revision' => $revision,
            ]));
    }

    public function activatePack(Request $request, string $instanceId, int $revision): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);

        if ($this->repository->pack($instanceId, $revision) === null) {
            abort(404);
        }

        $revision = $this->repository->republishPack($instanceId, $revision);

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-activated', [
                'revision' => $revision,
            ]));
    }

    public function destroyPack(Request $request, string $instanceId, int $revision): RedirectResponse
    {
        $this->requireInstance($request, $instanceId);

        $pack = $this->repository->pack($instanceId, $revision);

        if ($pack === null) {
            abort(404);
        }

        $this->repository->deletePack($instanceId, $revision);
        if (! $this->repository->hasPackFileReference($pack['disk_path'] ?? null)) {
            $this->packs->forget($pack['disk_path'] ?? null);
        }

        return redirect()
            ->route($this->adminRouteName($request, 'edit'), $instanceId)
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.pack-deleted'));
    }

    public function saveSettings(Request $request): RedirectResponse
    {
        $brand = $this->distributionBrand($request);
        $values = $request->validate([
            'min_version' => ['nullable', 'string', 'max:32'],
            'latest_version' => ['nullable', 'string', 'max:32'],
            'force_update' => ['nullable', 'boolean'],
            'cloud_map_url' => ['nullable', 'url', 'max:512', 'regex:~^https://[^/@]+(?:/.*)?$~i'],
        ]);

        $this->repository->saveSettings([
            'min_version' => $values['min_version'] ?? null,
            'latest_version' => $values['latest_version'] ?? null,
            'force_update' => (bool) ($values['force_update'] ?? false),
            'cloud_map_url' => $values['cloud_map_url'] ?? null,
        ], $brand);

        return redirect()
            ->route($this->adminRouteName($request, 'index'))
            ->with('sitmc_launcher_status', trans('sitmc-launcher::launcher.status.settings-saved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?string $instanceId = null): array
    {
        $unique = 'unique:'.LauncherRepository::instanceTable().',instance_id';

        if ($instanceId !== null) {
            $unique .= ','.$instanceId.',instance_id';
        }

        $values = $request->validate([
            'distribution_brand' => ['nullable', 'in:'.$this->distributionBrand($request)],
            'instance_id' => ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9._-]+$/', $unique],
            'name' => ['required', 'string', 'max:128'],
            'description' => ['nullable', 'string', 'max:2000'],
            'icon_url' => ['nullable', 'string', 'max:512'],
            'sort' => ['nullable', 'integer', 'min:-100000', 'max:100000'],
            'game_version' => ['required', 'string', 'max:64'],
            'loader' => ['nullable', 'in:vanilla,fabric,forge,neoforge,quilt'],
            'loader_version' => ['nullable', 'string', 'max:64'],
            'server_address' => ['nullable', 'string', 'max:255'],
            'server_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'opened_on' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'required' => ['nullable', 'boolean'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        if ($instanceId !== null && $values['instance_id'] !== $instanceId) {
            throw \Illuminate\Validation\ValidationException::withMessages(['instance_id' => '实例 ID 创建后不可修改。']);
        }

        return \SITMinecraft\Launcher\Access\Audience::input($request) + [
            'distribution_brand' => $this->distributionBrand($request),
            'instance_id' => $values['instance_id'],
            'name' => $values['name'],
            'description' => $values['description'] ?? null,
            'icon_url' => $values['icon_url'] ?? null,
            'sort' => (int) ($values['sort'] ?? 0),
            'game_version' => $values['game_version'],
            'loader' => $values['loader'] ?? null,
            'loader_version' => $values['loader_version'] ?? null,
            'server_address' => $values['server_address'] ?? null,
            'server_port' => $values['server_port'] ?? null,
            'opened_on' => $values['opened_on'] ?? null,
            'required' => (bool) ($values['required'] ?? false),
            'enabled' => (bool) ($values['enabled'] ?? false),
        ];
    }

    private function requireInstance(Request $request, string $instanceId): void
    {
        $this->requireDistribution($request, $this->repository->row($instanceId));
    }

    private function nextRevision(string $instanceId): int
    {
        return max(
            $this->repository->highestRevision($instanceId) + 1,
            (int) ($this->repository->row($instanceId)['revision'] ?? 1) + 1,
            1
        );
    }

    /**
     * @return array{max_upload: string, max_post: string, max_kilobytes: int}
     */
    private function uploadLimits(): array
    {
        return [
            'max_upload' => (string) ini_get('upload_max_filesize'),
            'max_post' => (string) ini_get('post_max_size'),
            'max_kilobytes' => $this->maxUploadKilobytes(),
        ];
    }

    /**
     * Largest upload the current PHP configuration accepts, in kilobytes, so the
     * validation rule fails with a message that matches the server's real limit.
     */
    private function maxUploadKilobytes(): int
    {
        $toBytes = static function (string $value): int {
            $value = trim($value);
            $unit = strtolower(substr($value, -1));
            $number = (int) $value;

            return match ($unit) {
                'g' => $number * 1024 * 1024 * 1024,
                'm' => $number * 1024 * 1024,
                'k' => $number * 1024,
                default => $number,
            };
        };

        $upload = $toBytes((string) ini_get('upload_max_filesize'));
        $post = $toBytes((string) ini_get('post_max_size'));
        $candidates = array_filter(
            [$upload, $post],
            static fn (int $bytes): bool => $bytes > 0
        );

        $limit = $candidates === [] ? 2 * 1024 * 1024 : min($candidates);

        return max(1, intdiv($limit, 1024));
    }
}
