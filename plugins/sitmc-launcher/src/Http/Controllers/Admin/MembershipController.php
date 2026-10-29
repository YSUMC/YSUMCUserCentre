<?php

namespace SITMinecraft\Launcher\Http\Controllers\Admin;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use SITMinecraft\Launcher\Access\Membership;
use SITMinecraft\Launcher\Access\BackendPolicy;
use SITMinecraft\Launcher\Access\MembershipCsv;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class MembershipController
{
    public function __construct(private Membership $memberships) {}

    public function index(Request $request): RedirectResponse
    {
        abort_unless(app(BackendPolicy::class)->allows($request->user(), 'users'), 403);
        return redirect()->to(url('admin/users'));
    }

    public function data(Request $request): JsonResponse
    {
        abort_unless(app(BackendPolicy::class)->allows($request->user(), 'users'), 403);
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
        abort_unless(app(BackendPolicy::class)->allows($request->user(), 'users', 2), 403);
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

    public function exportCsv(Request $request, MembershipCsv $csv): StreamedResponse
    {
        abort_unless(app(BackendPolicy::class)->allows($request->user(), 'users'), 403);
        return response()->streamDownload(function () use ($request, $csv) {
            $stream = fopen('php://output', 'wb');
            try { $csv->export($request->user(), $stream); } finally { fclose($stream); }
        }, 'ysumc-user-permissions-'.now()->format('Ymd-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function importCsv(Request $request, MembershipCsv $csv): JsonResponse
    {
        abort_unless(app(BackendPolicy::class)->allows($request->user(), 'users', 2), 403);
        $request->validate(['file' => ['required', 'file', 'max:10240']]);
        $result = $csv->import($request->user(), $request->file('file')->getContent());
        return response()->json($result + ['message' => '导入完成：更新 '.$result['updated'].' 人，未变更 '.$result['unchanged'].' 人。'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function row(User $actor, User $user): array
    {
        $membership = $this->memberships->describe($user);
        $isSuper = $actor->permission >= User::SUPER_ADMIN;
        $policy = app(BackendPolicy::class);
        $editable = $user->permission >= User::NORMAL && $policy->allows($actor, 'users', 2)
            && $policy->canManageTarget($actor, $user) && ($isSuper || $actor->uid !== $user->uid);
        $roles = $isSuper ? Membership::ROLES : array_slice(Membership::ROLES, 0, 4, true);
        if (!$isSuper) {
            $roles = array_filter($roles, function ($label, $role) use ($policy, $actor, $user) {
                $departments = in_array($role, ['member', 'minister'], true) ? array_keys(Membership::DEPARTMENTS) : [null];
                foreach ($departments as $department) if ($policy->canAssign($actor, $user, $role, $department)) return true;
                return false;
            }, ARRAY_FILTER_USE_BOTH);
            $editable = $editable && $roles !== [];
        }
        if ($actor->uid === $user->uid) {
            $roles = array_intersect_key($roles, [$membership['role'] => true]);
        }

        return ['uid' => $user->uid, 'nickname' => $user->nickname, 'membership' => $membership,
            'editable' => $editable, 'roles' => $roles, 'account_editable' => $actor->uid !== $user->uid
                && $policy->allows($actor, 'users', 2) && $policy->canManageTarget($actor, $user)];
    }

    public function userManagement(Request $request)
    {
        $policy = app(BackendPolicy::class);
        abort_unless($policy->allows($request->user(), 'users'), 403);
        $user = $request->user();
        $display = $user->toArray();
        // This DTO only controls core frontend buttons; no model is promoted.
        if ($policy->allows($user, 'users', 2)) $display['permission'] = max(1, $user->permission);
        return view('sitmc-launcher::admin.users', ['displayUser' => $display, 'membershipCsv' => [
            'canImport' => $policy->allows($user, 'users', 2),
            'exportUrl' => route('sitmc-launcher.admin.memberships.export'),
            'importUrl' => route('sitmc-launcher.admin.memberships.import'),
        ]]);
    }
}
