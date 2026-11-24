<?php

namespace SITMinecraft\Launcher\Access;

use App\Models\Player;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Auth\VerifiesPlayerToken;

final class Audience
{
    public const REQUEST_IDENTITY = 'sitmc.launcher.identity';

    public function __construct(private Membership $memberships) {}

    public function identity(Request $request, bool $required = false): ?array
    {
        if ($request->attributes->has(self::REQUEST_IDENTITY)) {
            $identity = $request->attributes->get(self::REQUEST_IDENTITY);
            abort_if($required && $identity === null, 401);
            return $identity;
        }
        $header = $request->header('Authorization');
        // FastCGI may forward an absent Authorization header as an empty string.
        if ($header === null || (is_string($header) && trim($header) === '')) {
            abort_if($required, 401, '请先登录皮肤站账号。');
            $request->attributes->set(self::REQUEST_IDENTITY, null);
            return null;
        }
        abort_unless(is_string($header) && preg_match('/^Bearer ([^\s]{1,8192})$/iD', $header, $match), 401, '登录令牌无效。');
        $verified = app(VerifiesPlayerToken::class)->verifiedIdentity($match[1]);
        abort_if($verified === null, 401, '登录已过期，请重新验证登录。');
        $player = Player::where('name', $verified['player'])->where('uid', $verified['uid'])->first();
        $user = $player === null ? null : User::find($verified['uid']);
        abort_if($user === null || $user->permission < User::NORMAL, 403, '此账号无权接收启动器内容。');
        $identity = $this->memberships->describe($user) + ['player' => $player->name];
        $request->attributes->set(self::REQUEST_IDENTITY, $identity);
        return $identity;
    }

    /** Legacy role selections include every department; scoped keys match one role and department. */
    public static function groups(): array
    {
        $groups = array_keys(Membership::ROLES);
        foreach (['member', 'minister'] as $role) {
            foreach (array_keys(Membership::DEPARTMENTS) as $department) $groups[] = $role.'_'.$department;
        }
        return $groups;
    }

    public static function roles(mixed $stored): ?array
    {
        if ($stored === null || $stored === '') return null;
        $roles = is_string($stored) ? json_decode($stored, true) : $stored;
        // Corrupt or empty restrictions must deny access, never become public.
        if (!is_array($roles) || !array_is_list($roles) || count(array_filter($roles, 'is_string')) !== count($roles)) return [];
        return array_values(array_intersect($roles, self::groups()));
    }

    public static function permits(array $row, ?array $identity): bool
    {
        $roles = self::roles($row['audience_roles'] ?? null);
        if ($roles === null) return true;
        if ($identity === null) return false;
        $role = $identity['role'] ?? '';
        if (in_array($role, $roles, true)) return true;
        $department = $identity['department'] ?? '';
        return in_array($role, ['member', 'minister'], true)
            && isset(Membership::DEPARTMENTS[$department])
            && in_array($role.'_'.$department, $roles, true);
    }

    public function filter(iterable $rows, Request $request): array
    {
        $identity = $this->identity($request);
        return array_values(array_filter(is_array($rows) ? $rows : iterator_to_array($rows), fn ($row) => self::permits($row, $identity)));
    }

    public static function publicRows(iterable $rows): array
    {
        return array_values(array_filter(is_array($rows) ? $rows : iterator_to_array($rows), fn ($row) => self::roles($row['audience_roles'] ?? null) === null));
    }

    public function settings(array $settings, Request $request, string $brand = 'ysumc'): array
    {
        $rows = app(\SITMinecraft\Launcher\Update\ReleaseRepository::class)->rows($brand);
        if (count(self::publicRows($rows)) === count($rows)) return $settings;
        $latest = (new \SITMinecraft\Launcher\Update\UpdateBuilder())->latest($this->filter($rows, $request), 'release');
        $settings['latest_version'] = $latest['version'] ?? null;
        if ($settings['min_version'] !== null && ($latest === null ||
            \SITMinecraft\Launcher\Update\SemanticVersion::compare($latest['version'], $settings['min_version']) < 0)) {
            $settings['min_version'] = null;
            $settings['force_update'] = false;
        }
        return $settings;
    }

    public static function input(Request $request): array
    {
        // Older admin clients may omit this field; preserve stored restrictions.
        if (!$request->has('audience_mode')) return [];
        $groups = self::groups();
        $values = $request->validate(['audience_mode' => ['nullable', 'in:public,groups'],
            'audience_roles' => ['nullable', 'array', 'max:'.count($groups)], 'audience_roles.*' => ['string', 'distinct', 'in:'.implode(',', $groups)]]);
        if (($values['audience_mode'] ?? 'public') === 'public') return ['audience_roles' => null];
        $roles = $values['audience_roles'] ?? [];
        if (!$roles) throw ValidationException::withMessages(['audience_roles' => '请至少选择一个接收等级或部门权限组。']);
        return ['audience_roles' => json_encode(array_values($roles), JSON_THROW_ON_ERROR)];
    }

    public static function headers(): array
    {
        return ['Cache-Control' => 'private, no-store', 'Vary' => 'Authorization'];
    }
}
