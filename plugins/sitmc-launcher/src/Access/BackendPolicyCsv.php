<?php

namespace SITMinecraft\Launcher\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Database\LauncherSchema;

final class BackendPolicyCsv
{
    public const MAX_BYTES = 2097152;
    private const META = ['功能标识（勿改）', '分类（只读）', '功能（只读）', '可设置上限（只读）', '说明（只读）'];
    private const REVISION = '校验标记（勿改）';

    public function __construct(private BackendPolicy $policy) {}

    private function groupHeaders(): array
    {
        $headers = [];
        foreach ($this->policy->groups() as $key => $label) $headers[$key] = $label.' ['.$key.']';
        return $headers;
    }

    private function snapshot(?string $raw): array
    {
        $matrix = $raw === null ? $this->policy->defaults() : json_decode($raw, true);
        $effective = [];
        foreach ($this->policy->groups() as $group => $_) {
            foreach ($this->policy->catalog() as $key => $module) {
                $row = is_array($matrix) ? ($matrix[$group] ?? []) : [];
                $value = is_array($row) ? ($row[$key] ?? 0) : 0;
                $effective[$group][$key] = in_array($value, [0, 1, 2], true) ? min($value, $module['max']) : 0;
            }
        }
        return ['revision' => hash('sha256', (string) $raw), 'matrix' => $effective];
    }

    private function token(string $revision, string $key): string
    {
        $limits = array_map(static fn (array $module) => $module['max'], $this->policy->catalog());
        ksort($limits);
        return hash_hmac('sha256', json_encode([$revision, $key, array_keys($this->groupHeaders()), $limits], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param resource $stream */
    public function export(User $actor, $stream): void
    {
        abort_unless($actor->permission >= User::SUPER_ADMIN, 403);
        // The values and their revision come from the same database read.
        $snapshot = $this->snapshot(DB::table(LauncherSchema::SETTINGS)->where('name', BackendPolicy::SETTING)->value('value'));
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, [...self::META, ...array_values($this->groupHeaders()), self::REVISION], ',', '"', '', "\r\n");
        foreach ($this->policy->catalog() as $key => $module) {
            $row = [$key, MembershipCsv::cell($module['section']), MembershipCsv::cell($module['label']),
                $module['max'], MembershipCsv::cell($module['note'])];
            foreach ($this->policy->groups() as $group => $_) $row[] = $snapshot['matrix'][$group][$key];
            $row[] = $this->token($snapshot['revision'], $key);
            fputcsv($stream, $row, ',', '"', '', "\r\n");
        }
    }

    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) $this->invalid('文件不能超过 2 MiB。');
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        if (preg_match('//u', $contents) !== 1) {
            $contents = @iconv('GB18030', 'UTF-8', $contents);
            if ($contents === false) $this->invalid('无法识别编码，请保存为 CSV UTF-8。');
        }
        if (str_contains($contents, "\0")) $this->invalid('请选择 CSV 文件，不支持 Excel 工作簿或 UTF-16 文本。');
        $stream = fopen('php://memory', 'r+');
        try {
            fwrite($stream, $contents); rewind($stream);
            $headers = fgetcsv($stream, 0, ',', '"', '');
            $expected = [...self::META, ...array_values($this->groupHeaders()), self::REVISION];
            if (!is_array($headers) || count($headers) !== count($expected) || count(array_unique($headers)) !== count($headers)
                || array_diff($expected, $headers)) $this->invalid('表头不匹配，请使用权限组配置页导出的 CSV，保留全部列；超级管理员不参与配置。');
            $rows = []; $record = 1;
            while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                ++$record;
                if ($fields === [null]) continue;
                if (count($fields) !== count($headers)) $this->invalid('第 '.$record.' 行列数不正确。');
                $row = array_combine($headers, $fields);
                $key = trim($row[self::META[0]]);
                $module = $this->policy->catalog()[$key] ?? null;
                if (!$module || isset($rows[$key])) $this->invalid('第 '.$record.' 行功能标识未知或重复。');
                if (trim($row[self::META[3]]) !== (string) $module['max']) $this->invalid('第 '.$record.' 行权限上限被修改，请重新导出。');
                $values = [];
                foreach ($this->groupHeaders() as $group => $header) {
                    $input = trim($row[$header]);
                    $level = array_search($input, BackendPolicy::LEVELS, true);
                    if ($level === false) $level = in_array($input, ['0', '1', '2'], true) ? (int) $input : null;
                    if ($level === null || $level > $module['max']) {
                        $this->invalid('第 '.$record.' 行“'.$header.' / '.$module['label'].'”权限无效，只能填 0、1、2，且不能超过该功能上限 '.$module['max'].'。');
                    }
                    $values[$group] = $level;
                }
                $token = trim($row[self::REVISION]);
                if (!preg_match('/^[0-9a-f]{64}$/D', $token)) $this->invalid('第 '.$record.' 行校验标记无效，请重新导出。');
                $rows[$key] = ['values' => $values, 'token' => $token, 'record' => $record];
            }
            if ($rows === []) $this->invalid('文件没有功能记录。');
            return $rows;
        } finally { fclose($stream); }
    }

    public function import(User $actor, string $contents): array
    {
        abort_unless($actor->permission >= User::SUPER_ADMIN, 403);
        $rows = $this->parse($contents);
        try {
            return DB::transaction(function () use ($actor, $rows) {
                $actor = User::where('uid', $actor->uid)->lockForUpdate()->firstOrFail();
                abort_unless($actor->permission >= User::SUPER_ADMIN, 403);
                $stored = DB::table(LauncherSchema::SETTINGS)->where('name', BackendPolicy::SETTING)->lockForUpdate()->first();
                $snapshot = $this->snapshot($stored?->value);
                $matrix = $snapshot['matrix']; $changed = []; $cells = 0;
                foreach ($rows as $key => $row) {
                    if (!hash_equals($this->token($snapshot['revision'], $key), $row['token'])) {
                        $this->invalid('第 '.$row['record'].' 行配置已过期、功能目录已变化或校验信息被修改，请重新导出。');
                    }
                    foreach ($row['values'] as $group => $value) {
                        if ($matrix[$group][$key] !== $value) {
                            $matrix[$group][$key] = $value;
                            $changed[$group] = true;
                            ++$cells;
                        }
                    }
                }
                // No writes until the entire file passes. Nested saves and audit
                // entries commit together with this outer transaction.
                foreach (array_keys($changed) as $group) {
                    $this->policy->save($actor, $group, $matrix[$group], $this->policy->revision());
                }
                return ['groups' => count($changed), 'permissions' => $cells, 'features' => count($rows)];
            });
        } finally {
            $this->policy->refresh();
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message.' 整批未导入。']);
    }
}
