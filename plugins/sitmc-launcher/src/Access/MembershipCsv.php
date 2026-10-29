<?php

namespace SITMinecraft\Launcher\Access;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Database\LauncherSchema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

final class MembershipCsv
{
    public const HEADERS = ['UID', '邮箱（勿改）', '昵称（只读）', '用户等级', '部门', '账号状态（只读）', '后台权限组（只读）', '校验标记（勿改）'];
    public const MAX_BYTES = 10485760;
    public const MAX_ROWS = 20000;

    public function __construct(private Membership $memberships, private BackendPolicy $policy) {}

    /** Excel must not interpret account-controlled strings as formulas. */
    public static function cell(string $value): string
    {
        return preg_match('/^[\s\p{Z}]*[=+@\-\t\r\n]/u', $value) ? "'".$value : $value;
    }

    private function revision(User $user, array $identity): string
    {
        return hash_hmac('sha256', json_encode([
            (int) $user->uid, $user->email, (int) $user->permission, $identity['role'], $identity['department'],
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }

    /** @param resource $stream */
    public function export(User $actor, $stream): void
    {
        abort_unless($this->policy->allows($actor, 'users'), 403);
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, self::HEADERS, ',', '"', '', "\r\n");
        foreach (User::orderBy('uid')->lazyById(250, 'uid') as $user) {
            $identity = $this->memberships->describe($user);
            $group = $identity['role'].($identity['department'] ? '_'.$identity['department'] : '');
            $state = match ((int) $user->permission) {
                User::BANNED => '已封禁', User::AUTH_ONLY => '仅认证', default => '正常',
            };
            fputcsv($stream, [
                $user->uid, self::cell((string) $user->email), self::cell((string) $user->nickname),
                $identity['role_label'], $identity['department_label'] ?? '', $state,
                $this->policy->groups()[$group] ?? $identity['role_label'], $this->revision($user, $identity),
            ], ',', '"', '', "\r\n");
        }
    }

    public function parse(string $contents): array
    {
        if (strlen($contents) > self::MAX_BYTES) $this->invalid('CSV 文件不能超过 10 MiB。');
        if (str_starts_with($contents, "\xEF\xBB\xBF")) $contents = substr($contents, 3);
        if (preg_match('//u', $contents) !== 1) {
            // Legacy Chinese Excel CSV is GBK/GB18030. Export always uses UTF-8 BOM.
            $contents = @iconv('GB18030', 'UTF-8', $contents);
            if ($contents === false) $this->invalid('无法识别文件编码，请另存为 CSV UTF-8。');
        }
        if (str_contains($contents, "\0")) $this->invalid('请选择 CSV 文件，不支持 Excel 工作簿或 UTF-16 文本。');
        $stream = fopen('php://memory', 'r+');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            if (!is_array($header) || count($header) !== count(self::HEADERS) || count(array_unique($header)) !== count($header)
                || array_diff(self::HEADERS, $header)) $this->invalid('表头不匹配，请使用“导出所有用户权限 CSV”下载的文件，保留全部列。');
            $rows = []; $record = 1;
            while (($fields = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                ++$record;
                if ($fields === [null]) continue;
                if (count($rows) >= self::MAX_ROWS) $this->invalid('每次最多导入 20000 个账号，请拆分文件。');
                if (count($fields) !== count($header)) $this->invalid('第 '.$record.' 行列数不正确。');
                $row = array_combine($header, $fields);
                $uid = trim($row['UID']);
                if (!ctype_digit($uid) || (int) $uid < 1 || (string) (int) $uid !== $uid) $this->invalid('第 '.$record.' 行 UID 必须是正整数。');
                if (isset($rows[$uid])) $this->invalid('第 '.$record.' 行 UID '.$uid.' 重复，请每个账号只保留一行。');
                $role = trim($row['用户等级']);
                $department = trim($row['部门']);
                $role = array_search($role, Membership::ROLES, true) ?: strtolower($role);
                $department = $department === '' ? null : (array_search($department, Membership::DEPARTMENTS, true) ?: strtolower($department));
                if (!isset(Membership::ROLES[$role])) $this->invalid('第 '.$record.' 行用户等级无效。');
                if ($department !== null && !isset(Membership::DEPARTMENTS[$department])) $this->invalid('第 '.$record.' 行部门无效。');
                $revision = trim($row['校验标记（勿改）']);
                if (!preg_match('/^[0-9a-f]{64}$/D', $revision)) $this->invalid('第 '.$record.' 行校验标记无效，请重新导出。');
                $rows[$uid] = ['uid' => (int) $uid, 'email' => $row['邮箱（勿改）'], 'role' => $role,
                    'department' => $department, 'revision' => $revision, 'record' => $record];
            }
            if ($rows === []) $this->invalid('CSV 没有用户记录。');
            return $rows;
        } finally {
            fclose($stream);
        }
    }

    public function import(User $actor, string $contents): array
    {
        abort_unless($this->policy->allows($actor, 'users', 2), 403);
        $rows = $this->parse($contents);
        return DB::transaction(function () use ($actor, $rows) {
            $users = User::whereIn('uid', array_unique([$actor->uid, ...array_keys($rows)]))
                ->orderBy('uid')->lockForUpdate()->get()->keyBy('uid');
            $actor = $users->get($actor->uid);
            // Freeze the group policy for this batch, after locking accounts in UID order.
            DB::table(LauncherSchema::SETTINGS)->where('name', BackendPolicy::SETTING)->lockForUpdate()->first();
            $this->policy->refresh();
            abort_unless($actor && $this->policy->allows($actor, 'users', 2), 403);
            $changes = []; $errors = [];
            foreach ($rows as $uid => $row) {
                $target = $users->get($uid);
                $prefix = '第 '.$row['record'].' 行（UID '.$uid.'）：';
                if (!$target) { $errors[] = $prefix.'账号不存在。'; continue; }
                $identity = $this->memberships->describe($target);
                if (!in_array($row['email'], [(string) $target->email, self::cell((string) $target->email)], true)
                    || !hash_equals($this->revision($target, $identity), $row['revision'])) {
                    $errors[] = $prefix.'账号或权限已变化，或 UID、邮箱、校验标记被修改，请重新导出。';
                    continue;
                }
                // Unchanged peers, the operator and banned accounts can round-trip safely.
                if ($row['role'] === $identity['role'] && $row['department'] === $identity['department']) continue;
                try {
                    $this->memberships->assertAssignable($actor, $target, $row['role'], $row['department']);
                    $changes[$uid] = $row;
                } catch (ValidationException $error) {
                    $errors[] = $prefix.implode(' ', array_merge(...array_values($error->errors())));
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 403) throw $error;
                    $errors[] = $prefix.($error->getMessage() ?: '无权修改此账号。');
                }
            }
            if ($errors !== []) {
                throw ValidationException::withMessages(['file' => array_merge(
                    ['整批未导入；共 '.count($errors).' 条错误，以下最多显示 30 条。'], array_slice($errors, 0, 30)
                )]);
            }
            // Every row is validated before events, writes and audit entries begin.
            foreach ($changes as $uid => $row) {
                $this->memberships->assign($actor, $users->get($uid), $row['role'], $row['department']);
            }
            return ['total' => count($rows), 'updated' => count($changes), 'unchanged' => count($rows) - count($changes)];
        });
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message.' 整批未导入。']);
    }
}
