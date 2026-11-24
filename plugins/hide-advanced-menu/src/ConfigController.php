<?php

namespace Blessing\HideAdvancedMenu;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use SITMinecraft\Launcher\Access\BackendPolicy;

final class ConfigController extends Controller
{
    public const PATH = 'admin/plugins/config/hide-advanced-menu';
    public const CAPABILITY = 'plugin_hide-advanced-menu';

    public function allowed(Request $request, int $level = 1): bool
    {
        $user = $request->user();
        if (!$user || $user->permission < User::NORMAL) return false;
        // Only use the policy when its plugin actually registered it. A disabled
        // policy plugin must not be auto-constructed with stale configuration.
        if (app()->bound(BackendPolicy::class)) {
            return app(BackendPolicy::class)->allows($user, self::CAPABILITY, $level);
        }

        return $user->permission >= User::ADMIN;
    }

    public function applications(Request $request): array
    {
        abort_unless($this->allowed($request), 403);
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'in:all,active,revoked'],
            'page' => ['nullable', 'integer', 'min:1', 'max:1000000'],
        ]);
        $search = trim($input['q'] ?? '');
        $status = $input['status'] ?? 'all';
        $query = DB::table('oauth_clients as c')
            ->leftJoin('users as u', 'u.uid', '=', 'c.user_id')
            ->leftJoin(CreationAudit::TABLE.' as a', 'a.client_id', '=', 'c.id');
        if ($status !== 'all') $query->where('c.revoked', $status === 'revoked');
        if ($search !== '') {
            // Bound parameters; use ! as a portable LIKE escape character.
            $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';
            $query->where(function ($q) use ($search, $pattern) {
                foreach (['c.name', 'c.redirect', 'u.email', 'u.nickname', 'a.actor_nickname'] as $column) {
                    $q->orWhereRaw($column." LIKE ? ESCAPE '!'", [$pattern]);
                }
                if (ctype_digit($search)) $q->orWhere('c.id', $search)->orWhere('c.user_id', $search)->orWhere('a.actor_uid', $search);
            });
        }
        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / 25));
        $page = min((int) ($input['page'] ?? 1), $lastPage);
        // Never select client_secret into a listing or serialize a Client model.
        $clients = $query->select([
            'c.id', 'c.user_id', 'c.name', 'c.provider', 'c.redirect',
            'c.personal_access_client', 'c.password_client', 'c.revoked',
            'c.created_at', 'c.updated_at', 'u.nickname as owner_name', 'u.email as owner_email',
            'a.actor_uid as creator_uid', 'a.actor_nickname as creator_name',
            'a.source as creation_source', 'a.recorded_at as creation_recorded_at',
        ])->selectRaw("CASE WHEN c.secret IS NULL OR c.secret = '' THEN 0 ELSE 1 END AS has_secret")
            ->orderByDesc('c.id')->forPage($page, 25)->get();
        return [
            'clients' => $clients, 'total' => $total, 'page' => $page, 'last_page' => $lastPage,
            'search' => $search, 'status' => $status, 'can_reveal' => $this->allowed($request, 2),
            'previous_url' => $page > 1 ? url(self::PATH).'?'.http_build_query(['q' => $search, 'status' => $status, 'page' => $page - 1]) : null,
            'next_url' => $page < $lastPage ? url(self::PATH).'?'.http_build_query(['q' => $search, 'status' => $status, 'page' => $page + 1]) : null,
        ];
    }

    public function render(Request $request)
    {
        abort_unless($request->isMethodSafe(), 405);
        return response()->view('Blessing\HideAdvancedMenu::config', $this->applications($request))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'same-origin');
    }

    public function secret(Request $request, string $client)
    {
        abort_unless($request->isMethod('POST'), 405);
        abort_unless($this->allowed($request, 2), 403);
        $row = DB::table('oauth_clients')->where('id', $client)->first(['secret']);
        abort_unless($row, 404);
        if (!$row->secret) {
            return response()->json(['message' => '此应用没有 client_secret（公共客户端）。'], 422)
                ->header('Cache-Control', 'no-store, private');
        }
        // Passport also supports hashed secrets. A stored hash is not a usable
        // credential and must never be shown as the original client_secret.
        if ((password_get_info($row->secret)['algo'] ?? null) !== null) {
            return response()->json(['message' => '此密钥以哈希保存，无法还原原文。'], 422)
                ->header('Cache-Control', 'no-store, private');
        }
        return response()->json(['client_id' => $client, 'client_secret' => $row->secret])
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'same-origin');
    }
}
