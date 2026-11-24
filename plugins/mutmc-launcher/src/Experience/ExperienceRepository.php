<?php

namespace MUTMC\Launcher\Experience;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MUTMC\Launcher\Access\Audience;
use MUTMC\Launcher\Database\LauncherSchema;

/** The presentation document is brand-scoped and never changes update policy. */
final class ExperienceRepository
{
    public const BRAND = 'mutmc';
    public const NAVIGATION = ['home', 'partners', 'skins', 'library', 'discover', 'screenshots', 'maps', 'activities', 'create', 'settings'];
    public const LABELS = ['home' => '首页', 'partners' => '友社', 'skins' => '皮肤', 'library' => '实例库', 'discover' => '发现内容', 'screenshots' => '截图', 'maps' => '在线地图', 'activities' => '社团活动', 'create' => '添加实例', 'settings' => '设置'];

    public function __construct() { LauncherSchema::install(); }

    private function key(): string { return (self::BRAND === 'mutmc' ? 'mutmc.' : '').'experience_v1'; }

    public function read(): array
    {
        $raw = DB::table(LauncherSchema::SETTINGS)->where('name', $this->key())->value('value');
        $stored = json_decode($raw ?? '', true);
        $document = is_array($stored) ? $stored : [];
        $legacyUrl = DB::table(LauncherSchema::SETTINGS)->where('name', (self::BRAND === 'mutmc' ? 'mutmc.' : '').'cloud_map_url')->value('value');
        $legacyPages = $legacyUrl ? [['id' => 'legacy-map', 'title' => '在线地图', 'kind' => 'map', 'url' => $legacyUrl, 'enabled' => true]] : [];
        return [
            'schema' => 1,
            'navigation' => array_replace(array_fill_keys(self::NAVIGATION, true), array_intersect_key((array) ($document['navigation'] ?? []), array_flip(self::NAVIGATION))),
            'pages' => array_values($document['pages'] ?? $legacyPages),
            'activities' => array_values($document['activities'] ?? []),
            'revision' => hash('sha256', $raw ?? ''),
        ];
    }

    public function published(Request $request): array
    {
        $document = $this->read();
        foreach (['pages', 'activities'] as $key) {
            $document[$key] = array_values(array_filter(app(Audience::class)->filter($document[$key], $request), static fn ($row) => ($row['enabled'] ?? false) === true));
            foreach ($document[$key] as &$row) unset($row['audience_roles'], $row['enabled']);
            unset($row);
        }
        unset($document['revision']);
        return $document;
    }

    public function save(array $input): void
    {
        $data = Validator::make($input, [
            'revision' => ['required', 'string', 'size:64'],
            'navigation' => ['required', 'array:'.implode(',', self::NAVIGATION)],
            'navigation.*' => ['boolean'],
            'pages' => ['nullable', 'array', 'max:32'],
            'pages.*.id' => ['required', 'regex:/^[a-zA-Z0-9_-]{1,64}$/D', 'distinct'],
            'pages.*.title' => ['required', 'string', 'max:60'],
            'pages.*.kind' => ['required', Rule::in(['map', 'website'])],
            'pages.*.url' => ['required', 'url:https', 'max:2048'],
            'pages.*.enabled' => ['boolean'],
            'activities' => ['nullable', 'array', 'max:100'],
            'activities.*.id' => ['required', 'regex:/^[a-zA-Z0-9_-]{1,64}$/D', 'distinct'],
            'activities.*.title' => ['required', 'string', 'max:120'],
            'activities.*.kind' => ['required', Rule::in(['announcement', 'event', 'checkin'])],
            'activities.*.description' => ['nullable', 'string', 'max:2000'],
            'activities.*.location' => ['nullable', 'string', 'max:200'],
            'activities.*.url' => ['nullable', 'url:https', 'max:2048'],
            'activities.*.starts_at' => ['nullable', 'date'],
            'activities.*.ends_at' => ['nullable', 'date'],
            'activities.*.enabled' => ['boolean'],
        ])->validate();
        $document = ['schema' => 1, 'navigation' => [], 'pages' => [], 'activities' => []];
        foreach (self::NAVIGATION as $key) $document['navigation'][$key] = filter_var($data['navigation'][$key] ?? true, FILTER_VALIDATE_BOOLEAN);
        foreach (['pages', 'activities'] as $section) {
            foreach ($data[$section] ?? [] as $index => $row) {
                $url = trim((string) ($row['url'] ?? ''));
                if ($url !== '' && (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null)) {
                    throw ValidationException::withMessages([$section.'.'.$index.'.url' => '网址不能包含账号或密码。']);
                }
                $entry = ['id' => $row['id'], 'title' => trim($row['title']), 'kind' => $row['kind'], 'url' => $url,
                    'enabled' => filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)];
                if ($section === 'activities') {
                    foreach (['description', 'location'] as $key) $entry[$key] = trim((string) ($row[$key] ?? ''));
                    foreach (['starts_at', 'ends_at'] as $key) $entry[$key] = empty($row[$key]) ? null : \Carbon\CarbonImmutable::parse($row[$key], 'Asia/Shanghai')->utc()->toIso8601String();
                    if ($entry['kind'] !== 'announcement' && $entry['starts_at'] === null) throw ValidationException::withMessages(['activities.'.$index.'.starts_at' => '请填写活动或打卡的开始时间。']);
                    if ($entry['ends_at'] !== null && ($entry['starts_at'] === null || strtotime($entry['ends_at']) < strtotime($entry['starts_at']))) throw ValidationException::withMessages(['activities.'.$index.'.ends_at' => '结束时间不能早于开始时间。']);
                }
                $document[$section][] = $entry;
            }
        }
        $encoded = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 60000) throw ValidationException::withMessages(['activities' => '内容过长，请精简介绍后保存。']);
        DB::transaction(function () use ($data, $encoded) {
            // Lock an existing settings row even before this optional document exists.
            DB::table(LauncherSchema::SETTINGS)->orderBy('name')->lockForUpdate()->get();
            $raw = DB::table(LauncherSchema::SETTINGS)->where('name', $this->key())->value('value');
            if (!hash_equals(hash('sha256', $raw ?? ''), $data['revision'])) throw ValidationException::withMessages(['revision' => '配置已被其他管理员修改，请刷新页面后重试。']);
            DB::table(LauncherSchema::SETTINGS)->updateOrInsert(['name' => $this->key()], ['value' => $encoded]);
        });
    }
}
