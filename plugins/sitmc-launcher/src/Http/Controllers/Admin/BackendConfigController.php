<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\BackendPolicy;
use SITMinecraft\Launcher\Database\LauncherSchema;

final class BackendConfigController
{
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
