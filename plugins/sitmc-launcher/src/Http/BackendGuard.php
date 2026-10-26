<?php

namespace SITMinecraft\Launcher\Http;

use App\Http\Middleware\CheckRole;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class BackendGuard extends CheckRole
{
    public function __construct(private BackendPolicy $policy) {}

    public function handle(Request $request, Closure $next, $role = null)
    {
        if (!BackendPolicy::backendPath($request->path())) {
            return $role === null ? $next($request) : parent::handle($request, $next, $role);
        }
        $user = $request->user();
        abort_unless($user && $user->permission >= User::NORMAL, 403);
        if ($role === 'super-admin') abort_unless($user->permission >= User::SUPER_ADMIN, 403);
        $capability = $this->policy->capability($request->path());
        if ($user->permission < User::SUPER_ADMIN && $capability === 'policy') abort(403, '只有超级管理员可以配置后台访问权限。');
        $required = $request->isMethodSafe() ? 1 : 2;
        if (!$this->policy->allows($user, $capability, $required)) {
            if ($request->isMethod('get') && $request->path() === 'admin' && ($landing = $this->policy->landing($user))) {
                return redirect()->to(url($landing));
            }
            abort(403, $required === 2 ? '当前权限组没有此页签的管理权限。' : '当前权限组无法访问此后台页签。');
        }
        // Do not permit lower groups to remove/disable the policy plugin, or
        // install executable plugin code to bypass this policy.
        if ($required === 2 && in_array($capability, ['plugins', 'market', 'update'], true)) {
            abort_unless($user->permission >= User::SUPER_ADMIN, 403);
        }
        if ($required === 2 && $capability === 'customize' && $request->input('option') === 'customJsCss') {
            abort_unless($user->permission >= User::SUPER_ADMIN, 403, '全站脚本和样式代码仅超级管理员可以修改。');
        }
        // Even outside the users tab, changing an account's closet is subject
        // to the same account hierarchy as user management.
        if ($required === 2 && $capability === 'closet' && ($target = $request->route('user')) instanceof User) {
            abort_unless($this->policy->canManageTarget($user, $target), 403, '不能修改权限高于自己的账号。');
        }
        return $next($request);
    }
}
