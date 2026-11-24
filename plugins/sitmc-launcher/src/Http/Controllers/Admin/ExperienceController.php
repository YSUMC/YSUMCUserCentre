<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use Illuminate\Http\Request;
use SITMinecraft\Launcher\Experience\ExperienceRepository;

final class ExperienceController
{
    public function __construct(private ExperienceRepository $repository) {}

    public function index(Request $request)
    {
        return view('sitmc-launcher::admin.experience', [
            'document' => $this->repository->read(), 'navigationLabels' => ExperienceRepository::LABELS,
            'distributionBrand' => ExperienceRepository::BRAND, 'brandLabel' => strtoupper(ExperienceRepository::BRAND),
            'adminIndexRoute' => 'sitmc-launcher.admin.index', 'adminRoutePrefix' => 'sitmc-launcher.admin.',
            'status' => session('launcher_experience_status'),
        ]);
    }

    public function save(Request $request)
    {
        $this->repository->save($request->all());
        return redirect()->route('sitmc-launcher.admin.experience')->with('launcher_experience_status', '已保存，启动器下次刷新时生效。');
    }
}
