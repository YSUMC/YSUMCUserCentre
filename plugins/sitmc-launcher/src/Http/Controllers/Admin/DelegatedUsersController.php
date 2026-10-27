<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Http\Controllers\UsersManagementController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class DelegatedUsersController extends UsersManagementController
{
    public function list(Request $request)
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:16384'], 'page' => ['nullable', 'integer', 'min:1']]);
        $term = trim($input['q'] ?? '');
        $query = User::query();
        try {
            // Keep advanced filters, but do not silently return every account
            // when a normal email or dotted nickname is rejected by the parser.
            $query->getModel()->getSearchStringManager()->build($query, $term);
        } catch (InvalidSearchStringException $exception) {
            // Discard any partially applied advanced filters before retrying.
            $pattern = '%'.addcslashes($term, '\\%_').'%';
            $query = User::query()->where(fn ($nested) => $nested
                ->where('email', 'like', $pattern)->orWhere('nickname', 'like', $pattern));
        }
        $query->orderBy('uid');
        $page = (int) ($input['page'] ?? 1);
        $results = (clone $query)->paginate(10, ['*'], 'page', $page);
        // Also recover stale pages from clients with an older cached script.
        if ($term !== '' && $page > 1 && $results->isEmpty() && $results->total() > 0) {
            return $query->paginate(10, ['*'], 'page', 1);
        }

        return $results;
    }

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
