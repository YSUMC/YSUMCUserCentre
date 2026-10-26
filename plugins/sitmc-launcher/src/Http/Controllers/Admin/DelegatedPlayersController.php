<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Http\Controllers\PlayersManagementController;
use App\Models\User;
use Illuminate\Http\Request;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class DelegatedPlayersController extends PlayersManagementController
{
    public function __construct()
    {
        $this->middleware(function (Request $request, $next) {
            $owner = $request->route('player')?->user;
            $actor = $request->user();
            $policy = app(BackendPolicy::class);
            abort_unless($actor && $owner && $policy->allows($actor, 'players', 2)
                && $policy->canManageTarget($actor, $owner), 403, '没有管理该角色所属账号的权限。');
            if ($request->route()->getActionMethod() === 'owner') {
                $uid = $request->validate(['uid' => 'required|integer'])['uid'];
                if ($newOwner = User::find($uid)) abort_unless($policy->canManageTarget($actor, $newOwner), 403, '不能向更高权限的账号转移角色。');
            }
            return $next($request);
        })->except(['list']);
    }
}
