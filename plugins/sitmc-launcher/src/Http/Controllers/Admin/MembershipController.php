<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use SITMinecraft\Launcher\Access\Membership;

final class MembershipController
{
    public function __construct(private Membership $memberships) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()?->isAdmin(), 403);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:120']]);
        $search = trim($input['q'] ?? '');
        $users = User::query()->when($search !== '', function ($query) use ($search) {
            $query->where(function ($where) use ($search) {
                $where->where('email', $search)->orWhere('nickname', $search);
                if (ctype_digit($search)) $where->orWhere('uid', (int) $search);
            });
        })->orderBy('uid')->paginate(30)->withQueryString();
        $isSuper = $request->user()->permission >= User::SUPER_ADMIN;
        $rows = $users->map(fn ($user) => ['uid' => $user->uid, 'email' => $user->email, 'nickname' => $user->nickname,
            'membership' => $this->memberships->describe($user), 'editable' => $user->permission >= User::NORMAL && ($isSuper || $user->permission < User::ADMIN)]);
        return view('sitmc-launcher::admin.memberships', ['rows' => $rows, 'pagination' => $users,
            'roles' => $isSuper ? Membership::ROLES : array_slice(Membership::ROLES, 0, 4, true),
            'departments' => Membership::DEPARTMENTS, 'search' => $search, 'status' => session('sitmc_launcher_status')]);
    }

    public function update(Request $request, int $uid): RedirectResponse
    {
        $values = $request->validate(['role' => ['required', 'string', 'in:'.implode(',', array_keys(Membership::ROLES))],
            'department' => ['nullable', 'string', 'in:'.implode(',', array_keys(Membership::DEPARTMENTS))]]);
        abort_unless($request->user()?->isAdmin(), 403);
        $this->memberships->assign($request->user(), User::findOrFail($uid), $values['role'], $values['department'] ?? null);
        return redirect()->route('sitmc-launcher.admin.memberships')->with('sitmc_launcher_status', '用户等级和部门已保存。');
    }
}
