<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\BackendPolicy;
use SITMinecraft\Launcher\Access\BackendPolicyCsv;
use SITMinecraft\Launcher\Database\LauncherSchema;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class BackendConfigController
{
    public function exportCsv(Request $request, BackendPolicyCsv $csv): StreamedResponse
    {
        abort_unless($request->user()?->permission >= User::SUPER_ADMIN, 403);
        return response()->streamDownload(function () use ($request, $csv) {
            $stream = fopen('php://output', 'wb');
            try { $csv->export($request->user(), $stream); } finally { fclose($stream); }
        }, 'ysumc-group-permissions-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function importCsv(Request $request, BackendPolicyCsv $csv): RedirectResponse
    {
        abort_unless($request->user()?->permission >= User::SUPER_ADMIN, 403);
        $request->validate(['file' => ['required', 'file', 'max:2048']]);
        $result = $csv->import($request->user(), $request->file('file')->getContent());
        return redirect()->to(url(BackendPolicy::CONFIG_PATH))->with('backend_access_status',
            'CSV 导入完成：更新 '.$result['groups'].' 个权限组、'.$result['permissions'].' 项功能权限，下次请求立即生效。');
    }

    public function render(Request $request, BackendPolicy $policy)
    {
        abort_unless($request->user()?->permission >= User::SUPER_ADMIN, 403);
        if ($request->isMethod('post')) {
            $input = $request->validate(['group' => 'required|string|max:64', 'permissions' => 'required|array', 'revision' => 'required|string|size:64']);
            $policy->save($request->user(), $input['group'], $input['permissions'], $input['revision']);
            return redirect()->to(url(BackendPolicy::CONFIG_PATH).'?group='.rawurlencode($input['group']))->with('backend_access_status', '该权限组的后台访问权限已保存，下次请求立即生效。');
        }
        abort_unless($request->isMethod('get') || $request->isMethod('head'), 405);
        $group = $request->query('group', 'admin');
        abort_unless(is_string($group) && isset($policy->groups()[$group]), 422, '无效的权限组。');
        $sections = [];
        foreach ($policy->catalog() as $key => $module) {
            $sections[$module['section']][] = ['key' => $key, 'value' => $policy->groupLevel($group, $key)] + $module;
        }
        return view('sitmc-launcher::admin.backend-access', ['groups' => $policy->groups(), 'group' => $group, 'sections' => $sections,
            'levels' => BackendPolicy::LEVELS, 'revision' => $policy->revision(), 'status' => session('backend_access_status'),
            'history' => DB::table(LauncherSchema::BACKEND_AUDIT)->orderByDesc('id')->limit(10)->get()]);
    }
}
