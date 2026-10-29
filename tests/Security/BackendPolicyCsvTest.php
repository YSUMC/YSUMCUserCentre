<?php

namespace Tests\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Access\{BackendPolicy, BackendPolicyCsv};
use SITMinecraft\Launcher\Database\LauncherSchema;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class BackendPolicyCsvTest extends TestCase
{
    private BackendPolicy $policy;
    private BackendPolicyCsv $csv;
    private User $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(BackendPolicy::class);
        $this->policy = $this->app->make(BackendPolicy::class);
        $this->csv = $this->app->make(BackendPolicyCsv::class);
        $this->super = $this->user();
        $this->super->forceFill(['permission' => User::SUPER_ADMIN])->save();
    }

    private function export(): string
    {
        $stream = fopen('php://memory', 'r+');
        try {
            $this->csv->export($this->super, $stream);
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    private function edited(array $changes, ?string $source = null, ?array $keep = null): string
    {
        $stream = fopen('php://memory', 'r+');
        try {
            fwrite($stream, substr($source ?? $this->export(), 3)); rewind($stream);
            $header = fgetcsv($stream, 0, ',', '"', '');
            $rows = [$header];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if ($keep !== null && !in_array($row[0], $keep, true)) continue;
                foreach ($changes[$row[0]] ?? [] as $group => $value) {
                    foreach ($header as $index => $label) if (str_ends_with($label, '['.$group.']')) $row[$index] = $value;
                }
                $rows[] = $row;
            }
            ftruncate($stream, 0); rewind($stream); fwrite($stream, "\xEF\xBB\xBF");
            foreach ($rows as $row) fputcsv($stream, $row, ',', '"', '', "\r\n");
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    private function rejected(string $file): void
    {
        try { $this->csv->import($this->super, $file); $this->fail('Import unexpectedly accepted'); }
        catch (ValidationException $error) { $this->assertNotEmpty($error->errors()['file']); }
    }

    public function testExportRoundTripsEveryConfigurableGroupAndFeature(): void
    {
        $file = $this->export();
        $this->assertStringNotContainsString('[super_admin]', $file);
        $this->assertStringContainsString('[member_operations]', $file);
        $this->assertCount(count($this->policy->catalog()), $this->csv->parse($file));
        $this->assertSame(['groups' => 0, 'permissions' => 0, 'features' => count($this->policy->catalog())], $this->csv->import($this->super, $file));
        $this->assertSame(0, DB::table(LauncherSchema::BACKEND_AUDIT)->count());
    }

    public function testPartialFileUpdatesMultipleGroupsAndKeepsOtherFeatures(): void
    {
        $file = $this->edited(['users' => ['normal' => '只读', 'member_operations' => '2']], keep: ['users']);
        $this->assertSame(['groups' => 2, 'permissions' => 2, 'features' => 1], $this->csv->import($this->super, $file));
        $this->assertSame(1, $this->policy->groupLevel('normal', 'users'));
        $this->assertSame(2, $this->policy->groupLevel('member_operations', 'users'));
        $this->assertSame(0, $this->policy->groupLevel('normal', 'players'));
        $this->assertSame(2, $this->policy->groupLevel('admin', 'players'));
        $this->assertSame(2, DB::table(LauncherSchema::BACKEND_AUDIT)->count());
        $this->rejected($file);
    }

    public function testRestrictedModulesCannotBeElevatedAndNoEarlierRowIsSaved(): void
    {
        foreach (['plugins' => '2', 'update' => '1', 'users' => '9'] as $feature => $level) {
            $this->rejected($this->edited(['players' => ['normal' => '1'], $feature => ['normal' => $level]]));
        }
        $this->assertSame(0, $this->policy->groupLevel('normal', 'players'));
        $this->assertSame(0, DB::table(LauncherSchema::BACKEND_AUDIT)->count());
    }

    public function testStaleFileCannotOverwriteLaterGroupConfiguration(): void
    {
        $file = $this->edited(['users' => ['normal' => '2']]);
        $row = $this->policy->defaults()['normal']; $row['users'] = 1;
        $this->policy->save($this->super, 'normal', $row, $this->policy->revision());
        $this->rejected($file);
        $this->assertSame(1, $this->policy->groupLevel('normal', 'users'));
        $this->assertSame(1, DB::table(LauncherSchema::BACKEND_AUDIT)->count());
    }

    public function testNonSuperAdminsCannotExportOrImport(): void
    {
        $file = $this->export();
        $admin = $this->user(); $admin->forceFill(['permission' => User::ADMIN])->save();
        $stream = fopen('php://memory', 'r+');
        try {
            foreach ([fn () => $this->csv->export($admin, $stream), fn () => $this->csv->import($admin, $file)] as $action) {
                try { $action(); $this->fail('Admin unexpectedly authorized'); }
                catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
            }
        } finally { fclose($stream); }
        // A stale authenticated model is rechecked under lock before writes.
        DB::table('users')->where('uid', $this->super->uid)->update(['permission' => User::ADMIN]);
        try { $this->csv->import($this->super, $file); $this->fail('Demoted operator authorized'); }
        catch (HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function testUnknownFeaturesSuperAdminColumnsAndDuplicateRowsAreRejected(): void
    {
        $file = $this->export();
        $this->rejected(str_replace('普通用户 [normal]', '超级管理员 [super_admin]', $file));
        $this->rejected(str_replace("\r\nusers,", "\r\nunknown_feature,", $file));
        $one = $this->edited([], $file, ['users']);
        $this->rejected($one.substr($one, strpos($one, "\r\n") + 2));
    }

    public function testLaterWriteFailureRollsBackAllGroupsAndAuditEntries(): void
    {
        $file = $this->edited(['users' => ['normal' => '1', 'member_operations' => '1']]);
        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            if (str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, LauncherSchema::BACKEND_AUDIT) && ++$writes === 2) {
                throw new \RuntimeException('synthetic second-group write failure');
            }
        });
        try { $this->csv->import($this->super, $file); $this->fail('Failure was not injected'); }
        catch (\RuntimeException $error) { $this->assertStringContainsString('synthetic second-group', $error->getMessage()); }
        $this->assertSame(0, $this->policy->groupLevel('normal', 'users'));
        $this->assertSame(0, $this->policy->groupLevel('member_operations', 'users'));
        $this->assertSame(0, DB::table(LauncherSchema::BACKEND_AUDIT)->count());
    }
}
