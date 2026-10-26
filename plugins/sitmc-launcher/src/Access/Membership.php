<?php

namespace SITMinecraft\Launcher\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Database\LauncherSchema;

final class Membership
{
    public const ROLES = [
        'normal' => '普通用户', 'member' => '社员', 'minister' => '部长',
        'president' => '社长', 'admin' => '管理员', 'super_admin' => '超级管理员',
    ];
    public const DEPARTMENTS = ['publicity' => '宣传部', 'operations' => '运维部', 'technology' => '技术部', 'building' => '建筑部'];

    public function __construct()
    {
        LauncherSchema::install();
    }

    public function describe(User $user): array
    {
        $stored = DB::table(LauncherSchema::MEMBERSHIPS)->where('uid', $user->uid)->first();
        $role = match (true) {
            $user->permission >= User::SUPER_ADMIN => 'super_admin',
            $user->permission === User::ADMIN => 'admin',
            default => in_array($stored->role ?? null, ['member', 'minister', 'president'], true) ? $stored->role : 'normal',
        };
        $department = in_array($role, ['member', 'minister'], true) && isset(self::DEPARTMENTS[$stored->department ?? ''])
            ? $stored->department : null;
        return ['role' => $role, 'role_label' => self::ROLES[$role], 'department' => $department,
            'department_label' => $department === null ? null : self::DEPARTMENTS[$department],
            'tags' => array_values(array_filter([self::ROLES[$role], self::DEPARTMENTS[$department ?? ''] ?? null]))];
    }

    public function assign(User $actor, User $target, string $role, ?string $department): void
    {
        if (!isset(self::ROLES[$role])) throw ValidationException::withMessages(['role' => '请选择有效的用户等级。']);
        if (in_array($role, ['member', 'minister'], true)) {
            if (!isset(self::DEPARTMENTS[$department ?? ''])) throw ValidationException::withMessages(['department' => '社员和部长必须选择一个部门。']);
        } elseif ($department !== null && $department !== '') {
            throw ValidationException::withMessages(['department' => '只有社员和部长可以设置部门。']);
        }
        DB::transaction(function () use ($actor, $target, $role, $department) {
            // Reload and lock both accounts: permissions in an earlier request are not authority.
            $users = User::whereIn('uid', [$actor->uid, $target->uid])->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
            $actor = $users->get($actor->uid);
            $target = $users->get($target->uid);
            $policy = app(BackendPolicy::class);
            $policy->forgetIdentities();
            abort_unless($actor && $target && $policy->canAssign($actor, $target, $role, $department), 403, '没有调整该用户等级或部门的权限，不能授予超出自己的权限。');
            abort_if($target->permission < User::NORMAL, 403, '请先在用户管理中处理账号状态。');
            $permission = match ($role) { 'admin' => User::ADMIN, 'super_admin' => User::SUPER_ADMIN, default => User::NORMAL };
            if ($actor->permission < User::SUPER_ADMIN) {
                abort_if($target->permission >= User::ADMIN || $permission >= User::ADMIN, 403);
            }
            abort_if($actor->uid === $target->uid && $permission !== $actor->permission, 403, '不能在此修改自己的管理权限。');
            if ($target->permission >= User::SUPER_ADMIN && $permission < User::SUPER_ADMIN) {
                abort_if(User::where('permission', '>=', User::SUPER_ADMIN)->lockForUpdate()->count() <= 1, 403, '必须保留至少一位超级管理员。');
            }
            $before = $this->describe($target);
            $oldPermission = $target->permission;
            if ($permission !== $oldPermission) {
                event('user.permission.updating', [$target, $permission]);
                $old = $target->replicate();
                $target->permission = $permission;
                $target->save();
                event('user.permission.updated', [$target, $old]);
            }
            DB::table(LauncherSchema::MEMBERSHIPS)->updateOrInsert(['uid' => $target->uid], [
                'role' => in_array($role, ['admin', 'super_admin'], true) ? 'normal' : $role,
                'department' => in_array($role, ['member', 'minister'], true) ? $department : null,
                'updated_by' => $actor->uid, 'updated_at' => now(),
            ]);
            DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->insert([
                'uid' => $target->uid, 'actor_uid' => $actor->uid,
                'before_value' => json_encode($before, JSON_THROW_ON_ERROR),
                'after_value' => json_encode($this->describe($target), JSON_THROW_ON_ERROR), 'created_at' => now(),
            ]);
            $policy->forgetIdentities();
        });
    }
}
