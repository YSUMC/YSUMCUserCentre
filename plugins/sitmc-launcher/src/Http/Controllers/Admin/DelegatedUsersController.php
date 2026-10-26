<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Http\Controllers\UsersManagementController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class DelegatedUsersController extends UsersManagementController
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            return DB::transaction(function () use ($request, $next) {
                $target = $request->route('user');
                $actor = $request->user();
                abort_unless($actor && $target instanceof User, 403);
                $users = User::whereIn('uid', [$actor->uid, $target->uid])->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
                $actor = $users->get($actor->uid); $target = $users->get($target->uid);
                $policy = app(BackendPolicy::class);
                $policy->forgetIdentities();
                abort_unless($actor && $target && $policy->allows($actor, 'users', 2)
                    && $policy->canManageTarget($actor, $target), 403, '没有管理该账号的权限。');
                if ($request->route()->getActionMethod() === 'delete') abort_if($actor->uid === $target->uid, 403, '不能删除当前登录账号。');
                if (in_array($request->route()->getActionMethod(), ['permission', 'delete'], true) && $target->permission >= User::SUPER_ADMIN) {
                    abort_if(User::where('permission', '>=', User::SUPER_ADMIN)->lockForUpdate()->count() <= 1, 403, '必须保留至少一位超级管理员。');
                }
                $previousResolver = $request->getUserResolver();
                $request->setUserResolver(fn () => $actor);
                $request->route()->setParameter('user', $target);
                try { return $next($request); } finally { $request->setUserResolver($previousResolver); }
            }, 3);
        })->except(['list']);
    }
}
