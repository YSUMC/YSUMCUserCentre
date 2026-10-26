<?php

namespace MUTMC\Launcher\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Membership
{
    public const ROLES = [
        'normal' => '普通用户', 'member' => '社员', 'minister' => '部长',
        'president' => '社长', 'admin' => '管理员', 'super_admin' => '超级管理员',
    ];
    public const DEPARTMENTS = ['publicity' => '宣传部', 'operations' => '运维部', 'technology' => '技术部', 'building' => '建筑部'];

    public function describe(User $user): array
    {
        // Optional read-only integration: this plugin never owns the membership table.
        $table = 'sitmc_launcher_memberships';
        $stored = Schema::hasTable($table) ? DB::table($table)->where('uid', $user->uid)->first() : null;
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

}
