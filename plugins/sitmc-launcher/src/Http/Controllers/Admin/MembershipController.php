<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use SITMinecraft\Launcher\Access\Membership;

final class MembershipController
{
    public function __construct(private Membership $memberships) {}

    public function index(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        return redirect()->to(url('admin/users'));
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $input = $request->validate([
            'uids' => ['required', 'array', 'min:1', 'max:100'],
            'uids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ]);
        $users = User::whereIn('uid', $input['uids'])->get();

        return response()->json([
            'users' => $users->map(fn (User $user) => $this->row($request->user(), $user))->values(),
            'departments' => Membership::DEPARTMENTS,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $uid): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $values = $request->validate(['role' => ['required', 'string', 'in:'.implode(',', array_keys(Membership::ROLES))],
            'department' => ['nullable', 'string', 'in:'.implode(',', array_keys(Membership::DEPARTMENTS))]]);
        $user = User::findOrFail($uid);
        $this->memberships->assign($request->user(), $user, $values['role'], $values['department'] ?? null);
        if ($request->expectsJson()) {
            return response()->json(['message' => '用户等级和部门已保存。', 'user' => $this->row($request->user(), $user->refresh())])
                ->header('Cache-Control', 'private, no-store');
        }

        return redirect()->to(url('admin/users'))->with('sitmc_launcher_status', '用户等级和部门已保存。');
    }

    private function row(User $actor, User $user): array
    {
        $membership = $this->memberships->describe($user);
        $isSuper = $actor->permission >= User::SUPER_ADMIN;
        $editable = $user->permission >= User::NORMAL && ($isSuper || $user->permission < User::ADMIN);
        $roles = $isSuper ? Membership::ROLES : array_slice(Membership::ROLES, 0, 4, true);
        if ($actor->uid === $user->uid) {
            $roles = array_intersect_key($roles, [$membership['role'] => true]);
        }

        return ['uid' => $user->uid, 'nickname' => $user->nickname, 'membership' => $membership,
            'editable' => $editable, 'roles' => $roles];
    }
}
