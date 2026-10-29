<?php

namespace Tests\Security;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Access\{BackendPolicy, Membership, MembershipCsv};
use SITMinecraft\Launcher\Database\LauncherSchema;

final class MembershipCsvTest extends TestCase
{
    private MembershipCsv $csv;
    private Membership $memberships;
    private User $super;
    private User $first;
    private User $second;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->singleton(BackendPolicy::class);
        $this->memberships = new Membership;
        $this->csv = $this->app->make(MembershipCsv::class);
        $this->super = $this->user();
        $this->super->forceFill(['permission' => User::SUPER_ADMIN])->save();
        $this->first = $this->user();
        $this->second = $this->user();
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

    private function edited(array $changes, ?string $source = null): string
    {
        $stream = fopen('php://memory', 'r+');
        try {
            fwrite($stream, substr($source ?? $this->export(), 3));
            rewind($stream);
            $rows = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                if (isset($changes[$row[0]])) foreach ($changes[$row[0]] as $column => $value) $row[$column] = $value;
                $rows[] = $row;
            }
            ftruncate($stream, 0); rewind($stream);
            fwrite($stream, "\xEF\xBB\xBF");
            foreach ($rows as $row) fputcsv($stream, $row, ',', '"', '', "\r\n");
            rewind($stream);
            return stream_get_contents($stream);
        } finally { fclose($stream); }
    }

    private function rejected(string $file, ?User $actor = null): void
    {
        try { $this->csv->import($actor ?? $this->super, $file); $this->fail('Import unexpectedly succeeded'); }
        catch (ValidationException $error) { $this->assertNotEmpty($error->errors()['file']); }
    }

    public function testFullRoundTripIncludesBannedUsersAndEscapesFormulaCells(): void
    {
        $this->first->forceFill(['nickname' => '=HYPERLINK("https://example.invalid")'])->save();
        $this->second->forceFill(['permission' => User::BANNED])->save();
        $file = $this->export();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $file);
        $this->assertStringContainsString("'=HYPERLINK", $file);
        $this->assertCount(3, $this->csv->parse($file));
        $this->assertSame(['total' => 3, 'updated' => 0, 'unchanged' => 3], $this->csv->import($this->super, $file));
        $this->assertSame(User::BANNED, $this->second->refresh()->permission);
        $this->assertSame(0, DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->count());
    }

    public function testBatchUpdatesRolesAndDepartmentsWithAudit(): void
    {
        $file = $this->edited([$this->first->uid => [3 => '社员', 4 => '技术部'], $this->second->uid => [3 => 'admin']]);
        $this->assertSame(['total' => 3, 'updated' => 2, 'unchanged' => 1], $this->csv->import($this->super, $file));
        $this->assertSame('technology', $this->memberships->describe($this->first->refresh())['department']);
        $this->assertSame(User::ADMIN, $this->second->refresh()->permission);
        $this->assertSame(2, DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->count());
        $this->rejected($file); // Replay cannot overwrite later permissions.
    }

    public function testOneInvalidDepartmentRejectsEveryRowBeforeWrites(): void
    {
        $file = $this->edited([$this->first->uid => [3 => '部长', 4 => '建筑部'], $this->second->uid => [3 => '社员']]);
        $this->rejected($file);
        $this->assertSame('normal', $this->memberships->describe($this->first)['role']);
        $this->assertSame(0, DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->count());
    }

    public function testOrdinaryAdminCannotPromoteAnotherAdmin(): void
    {
        $this->first->forceFill(['permission' => User::ADMIN])->save();
        $this->rejected($this->edited([$this->second->uid => [3 => '超级管理员']]), $this->first);
        $this->assertSame(User::NORMAL, $this->second->refresh()->permission);
    }

    public function testStaleAndTamperedSnapshotsRejectTheWholeBatch(): void
    {
        $file = $this->edited([$this->first->uid => [3 => '社员', 4 => '宣传部']]);
        $this->memberships->assign($this->super, $this->second, 'minister', 'operations');
        $this->rejected($file);
        $this->assertSame('normal', $this->memberships->describe($this->first)['role']);
        $this->rejected($this->edited([$this->first->uid => [3 => 'admin', 7 => str_repeat('0', 64)]]));
        $this->assertSame(User::NORMAL, $this->first->refresh()->permission);
    }

    public function testSelfDemotionAndBannedAccountChangesAreRejected(): void
    {
        $this->rejected($this->edited([$this->super->uid => [3 => '普通用户']]));
        $this->second->forceFill(['permission' => User::BANNED])->save();
        $this->rejected($this->edited([$this->second->uid => [3 => '社员', 4 => '宣传部']]));
        $this->assertSame(User::SUPER_ADMIN, $this->super->refresh()->permission);
        $this->assertSame(User::BANNED, $this->second->refresh()->permission);
    }

    public function testReadOnlyOperatorCannotImportEvenUnchangedFile(): void
    {
        $this->first->forceFill(['permission' => User::ADMIN])->save();
        $policy = $this->app->make(BackendPolicy::class);
        $matrix = $policy->defaults(); $matrix['admin']['users'] = 1;
        DB::table(LauncherSchema::SETTINGS)->insert(['name' => BackendPolicy::SETTING, 'value' => json_encode($matrix)]);
        $policy->refresh();
        try { $this->csv->import($this->first, $this->export()); $this->fail('Read-only import accepted'); }
        catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) { $this->assertSame(403, $error->getStatusCode()); }
    }

    public function testDuplicateUidAndUnknownAccountAreRejected(): void
    {
        $this->rejected($this->edited([$this->second->uid => [0 => (string) $this->first->uid]]));
        $this->rejected($this->edited([$this->second->uid => [0 => '999999']]));
        $this->assertSame(0, DB::table(LauncherSchema::MEMBERSHIP_AUDIT)->count());
    }
}
