<?php

namespace SITMinecraft\Launcher\Access;

use App\Models\User;
use App\Services\PluginManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Database\LauncherSchema;

/** Request-scoped policy. No user is promoted to a core administrator. */
final class BackendPolicy
{
    public const SETTING = 'backend_access_v1';
    public const CONFIG_PATH = 'admin/plugins/config/sitmc-launcher';
    public const LEVELS = [0 => '不可访问', 1 => '只读', 2 => '可管理'];
    private ?array $catalog = null;
    private ?array $matrix = null;
    private array $identities = [];

    public function __construct(private Membership $memberships) {}

    public function groups(): array
    {
        $groups = ['normal' => '普通用户'];
        foreach (['member' => '社员', 'minister' => '部长'] as $role => $label) {
            foreach (Membership::DEPARTMENTS as $department => $departmentLabel) {
                $groups[$role.'_'.$department] = $departmentLabel.' · '.$label;
            }
            $groups[$role] = $label.'（未分配部门）';
        }
        return $groups + ['president' => '社长', 'admin' => '管理员'];
    }

    public function catalog(): array
    {
        if ($this->catalog !== null) return $this->catalog;
        $catalog = [];
        $add = static function ($key, $label, $section, $path, $prefixes, $max = 2, $note = '') use (&$catalog) {
            $catalog[$key] = compact('label', 'section', 'path', 'prefixes', 'max', 'note');
        };
        $add('dashboard', '仪表盘与站内通知', '站点管理', 'admin', ['admin/chart', 'admin/notifications', 'api/admin/notifications']);
        $add('users', '用户管理与等级部门', '账号管理', 'admin/users', ['admin/users', 'api/admin/users', 'admin/launcher/users']);
        $add('players', '角色管理', '账号管理', 'admin/players', ['admin/players', 'api/admin/players']);
        $add('closet', '用户衣柜管理接口', '账号管理', '', ['admin/closet', 'api/admin/closet']);
        $add('reports', '举报管理', '账号管理', 'admin/reports', ['admin/reports', 'api/admin/reports']);
        foreach (['customize' => '外观定制', 'i18n' => '语言管理', 'score' => '积分设置', 'options' => '站点设置', 'resource' => '资源设置', 'status' => '运行状态'] as $key => $label) {
            $add($key, $label, '站点管理', 'admin/'.$key, ['admin/'.$key], $key === 'status' ? 1 : 2);
        }
        foreach (['ysumc' => 'YSUMC 启动器', 'mutmc' => 'MUTMC 启动器'] as $brand => $label) {
            $base = 'admin/launcher'.($brand === 'mutmc' ? '/mutmc' : '');
            $add($brand.'_instances', '实例、整合包与更新策略', $label, $base, array_merge([$base.'/instances', $base.'/settings'], $brand === 'mutmc' ? ['admin/plugins/config/mutmc-launcher'] : []));
            $add($brand.'_announcements', '公告', $label, $base.'/announcements', [$base.'/announcements']);
            $add($brand.'_releases', '启动器版本', $label, $base.'/releases', [$base.'/releases']);
        }
        $add('interop', '友站互联', 'YSUMC 启动器', 'admin/launcher/interop', ['admin/launcher/interop']);
        $add('invitations', '注册邀请码', '插件功能', 'admin/invitation-codes', ['admin/invitation-codes']);
        $add('ygg_log', 'Yggdrasil 日志', '插件功能', 'admin/yggdrasil-log', ['admin/yggdrasil-log'], 1);
        $add('plugins', '插件管理', '系统维护', 'admin/plugins/manage', ['admin/plugins/manage', 'admin/plugins/data', 'admin/plugins/readme', 'admin/plugins/upload', 'admin/plugins/wget'], 1, '启停、删除及上传插件仅超级管理员可操作。');
        $add('market', '插件市场', '系统维护', 'admin/plugins/market', ['admin/plugins/market'], 1, '安装插件仅超级管理员可操作。');
        $add('update', '系统升级', '系统维护', 'admin/update', ['admin/update'], 0, '沿用核心限制，仅超级管理员可访问。');
        foreach (app(PluginManager::class)->getEnabledPlugins() as $plugin) {
            if (in_array($plugin->name, ['sitmc-launcher', 'mutmc-launcher'], true) || !$plugin->hasConfig()) continue;
            $prefixes = ['admin/plugins/config/'.$plugin->name];
            if ($plugin->name === 'restricted-email-domains') $prefixes[] = 'admin/restricted-email-domains';
            $secret = $plugin->name === 'yggdrasil-connect';
            $add('plugin_'.$plugin->name, trans($plugin->title), '插件配置', 'admin/plugins/config/'.$plugin->name, $prefixes, $secret ? 0 : 2,
                $secret ? '包含登录签名私钥，仅超级管理员可以访问。' : '');
        }
        return $this->catalog = $catalog;
    }

    public function group(User $user): string
    {
        $identity = $this->identity($user);
        return $identity['role'].($identity['department'] ? '_'.$identity['department'] : '');
    }

    private function identity(User $user): array
    {
        return $this->identities[$user->uid.':'.$user->permission] ??= $this->memberships->describe($user);
    }

    public function forgetIdentities(): void { $this->identities = []; }

    public function refresh(): void
    {
        $this->matrix = null;
        $this->forgetIdentities();
    }

    public function defaults(): array
    {
        $matrix = [];
        foreach ($this->groups() as $group => $_) {
            foreach ($this->catalog() as $key => $module) $matrix[$group][$key] = $group === 'admin' ? $module['max'] : 0;
        }
        return $matrix;
    }

    public function matrix(): array
    {
        if ($this->matrix !== null) return $this->matrix;
        $raw = DB::table(LauncherSchema::SETTINGS)->where('name', self::SETTING)->value('value');
        if ($raw === null) return $this->matrix = $this->defaults();
        $decoded = json_decode($raw, true);
        // A corrupt saved policy fails closed; only an absent policy uses defaults.
        return $this->matrix = is_array($decoded) ? $decoded : [];
    }

    public function revision(): string
    {
        return hash('sha256', (string) DB::table(LauncherSchema::SETTINGS)->where('name', self::SETTING)->value('value'));
    }

    public function level(?User $user, ?string $capability): int
    {
        if (!$user || $user->permission < User::NORMAL) return 0;
        if ($user->permission >= User::SUPER_ADMIN) return 2;
        if (!isset($this->catalog()[$capability ?? ''])) return 0;
        return $this->groupLevel($this->group($user), $capability);
    }

    public function groupLevel(string $group, string $capability): int
    {
        $row = $this->matrix()[$group] ?? [];
        $value = is_array($row) ? ($row[$capability] ?? 0) : 0;
        return in_array($value, [0, 1, 2], true) ? min($value, $this->catalog()[$capability]['max'] ?? 0) : 0;
    }

    public function allows(?User $user, ?string $capability, int $level = 1): bool
    {
        return $this->level($user, $capability) >= $level;
    }

    public static function backendPath(string $path): bool
    {
        $path = trim($path, '/');
        return $path === 'admin' || str_starts_with($path, 'admin/') || $path === 'api/admin' || str_starts_with($path, 'api/admin/');
    }

    public function capability(string $path): ?string
    {
        $path = trim($path, '/');
        if ($path === self::CONFIG_PATH || str_starts_with($path, self::CONFIG_PATH.'/')) return 'policy';
        if ($path === 'admin') return 'dashboard';
        if ($path === 'admin/launcher') return 'ysumc_instances';
        if ($path === 'admin/launcher/mutmc') return 'mutmc_instances';
        foreach ($this->catalog() as $key => $module) {
            foreach ($module['prefixes'] as $prefix) {
                if ($path === $prefix || str_starts_with($path, $prefix.'/')) return $key;
            }
        }
        return null;
    }

    public function landing(?User $user): ?string
    {
        if ($user?->permission >= User::SUPER_ADMIN) return 'admin';
        foreach ($this->catalog() as $key => $module) {
            if ($module['path'] && $this->allows($user, $key)) return $module['path'];
        }
        return null;
    }

    public function filterMenu(array $menu, ?User $user): array
    {
        $items = [];
        foreach ($menu as $item) {
            if (isset($item['children'])) {
                $item['children'] = $this->filterMenu($item['children'], $user);
                if ($item['children'] !== []) $items[] = $item;
                continue;
            }
            $path = trim($item['link'] ?? '', '/');
            if ($path === self::CONFIG_PATH) $item['title'] = '后台访问权限（等级 / 部门）';
            if ($this->allows($user, $this->capability($path))) { $items[] = $item; continue; }
            // A launcher can expose announcements without exposing its instances.
            if (in_array($path, ['admin/launcher', 'admin/launcher/mutmc'], true)) {
                foreach (['announcements', 'releases'] as $tab) {
                    if ($this->allows($user, $this->capability($path.'/'.$tab))) {
                        $item['link'] = $path.'/'.$tab; $items[] = $item; break;
                    }
                }
            }
        }
        return array_values($items);
    }

    private function rank(User $user): int
    {
        return ['normal' => 0, 'member' => 1, 'minister' => 2, 'president' => 3, 'admin' => 4, 'super_admin' => 5][$this->identity($user)['role']];
    }

    public function canManageTarget(User $actor, User $target): bool
    {
        if ($actor->permission >= User::SUPER_ADMIN || $actor->uid === $target->uid) return true;
        if ($target->permission >= User::ADMIN || $this->rank($target) > $this->rank($actor)) return false;
        foreach ($this->catalog() as $key => $_) {
            // Compare dormant memberships too: unbanning must not resurrect
            // permissions stronger than the acting user's own group.
            if ($this->groupLevel($this->group($target), $key) > $this->level($actor, $key)) return false;
        }
        return true;
    }

    public function canAssign(User $actor, User $target, string $role, ?string $department): bool
    {
        if (!$this->allows($actor, 'users', 2) || !$this->canManageTarget($actor, $target)) return false;
        if ($actor->permission >= User::SUPER_ADMIN) return true;
        if ($actor->uid === $target->uid || in_array($role, ['admin', 'super_admin'], true)) return false;
        $rank = ['normal' => 0, 'member' => 1, 'minister' => 2, 'president' => 3][$role] ?? 99;
        if ($rank > $this->rank($actor)) return false;
        $group = $role.($department ? '_'.$department : '');
        foreach ($this->catalog() as $key => $_) {
            if ($this->groupLevel($group, $key) > $this->level($actor, $key)) return false;
        }
        return true;
    }

    public function save(User $actor, string $group, array $permissions, string $revision): void
    {
        abort_unless($actor->permission >= User::SUPER_ADMIN, 403);
        if (!isset($this->groups()[$group])) throw ValidationException::withMessages(['group' => '请选择可配置的权限组。']);
        $clean = [];
        foreach ($this->catalog() as $key => $module) {
            $value = $permissions[$key] ?? null;
            if (!in_array($value, [0, 1, 2, '0', '1', '2'], true) || (int) $value > $module['max']) {
                throw ValidationException::withMessages(['permissions' => '页签权限无效，请重新载入配置页。']);
            }
            $clean[$key] = (int) $value;
        }
        if (array_diff(array_keys($permissions), array_keys($clean))) throw ValidationException::withMessages(['permissions' => '包含未知页签，请重新载入配置页。']);
        DB::transaction(function () use ($actor, $group, $clean, $revision) {
            $actor = User::where('uid', $actor->uid)->lockForUpdate()->firstOrFail();
            abort_unless($actor->permission >= User::SUPER_ADMIN, 403);
            DB::table(LauncherSchema::SETTINGS)->insertOrIgnore(['name' => self::SETTING, 'value' => null]);
            $row = DB::table(LauncherSchema::SETTINGS)->where('name', self::SETTING)->lockForUpdate()->first();
            if (!hash_equals(hash('sha256', (string) $row->value), $revision)) {
                throw ValidationException::withMessages(['revision' => '权限配置已被其他操作更新，请刷新后重试。']);
            }
            $matrix = $row->value === null ? $this->defaults() : json_decode($row->value, true);
            if (!is_array($matrix)) $matrix = [];
            $before = $matrix[$group] ?? [];
            $matrix[$group] = $clean;
            unset($matrix['super_admin']);
            DB::table(LauncherSchema::SETTINGS)->where('name', self::SETTING)->update(['value' => json_encode($matrix, JSON_THROW_ON_ERROR)]);
            DB::table(LauncherSchema::BACKEND_AUDIT)->insert(['group_key' => $group, 'actor_uid' => $actor->uid,
                'before_value' => json_encode($before, JSON_THROW_ON_ERROR), 'after_value' => json_encode($clean, JSON_THROW_ON_ERROR), 'created_at' => now()]);
            $this->matrix = $matrix;
        });
    }
}
