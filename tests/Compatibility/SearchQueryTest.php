<?php

namespace Tests\Compatibility;

use App\Models\Player;
use App\Models\User;
use Lorisleiva\LaravelSearchString\Exceptions\InvalidSearchStringException;

class SearchQueryTest extends TestCase
{
    public function testBooleanListSortAndUserScope(): void
    {
        config(['search-string.fail' => 'exceptions']);
        $a = $this->makeUser();
        $b = $this->makeUser(1);
        $c = $this->makeUser(2);
        $ids = [$a->uid, $b->uid, $c->uid];
        $query = User::whereIn('uid', $ids)->usingSearchString(
            '(permission:0 or permission:1) not permission:2 sort:-uid limit:1'
        );
        $this->assertSame([$b->uid], $query->get()->pluck('uid')->all());
        $this->assertSame([$a->uid, $c->uid], User::whereIn('uid', $ids)
            ->usingSearchString('permission in (0,2) sort:uid')->get()->pluck('uid')->all());
        // A search OR must not escape the caller's existing ownership scope.
        $this->assertSame([$a->uid], User::where('uid', $a->uid)
            ->usingSearchString('permission:0 or uid:'.$b->uid)->get()->pluck('uid')->all());
    }

    public function testUntrustedValuesStayInBindingsAndAliasesStillWork(): void
    {
        config(['search-string.fail' => 'exceptions']);
        $user = $this->makeUser();
        $value = "x' OR 1=1 --";
        $user->update(['nickname' => $value]);
        $query = User::usingSearchString('nickname:"'.$value.'"');
        $this->assertStringNotContainsString($value, $query->toSql());
        $this->assertContains($value, $query->getBindings());
        $this->assertSame([$user->uid], $query->get()->pluck('uid')->all());
        $player = Player::factory()->create(['uid' => $user->uid, 'tid_skin' => 0, 'tid_cape' => 0]);
        $this->assertSame([$player->pid], Player::where('uid', $user->uid)
            ->usingSearchString('skin:0 cape:0')->get()->pluck('pid')->all());
    }

    public function testDateBooleanNullAndChineseSearch(): void
    {
        config(['search-string.fail' => 'exceptions']);
        $user = $this->makeUser();
        $user->forceFill(['nickname' => '燕山 搜索测试', 'verified' => true, 'ip' => '127.0.0.1'])->save();
        $this->assertSame([$user->uid], User::where('uid', $user->uid)
            ->usingSearchString('"燕山" verified register_at >= "2020-01-01" not ip:NULL')
            ->get()->pluck('uid')->all());
        $this->assertSame(0, User::where('uid', $user->uid)->usingSearchString('not verified')->count());
    }

    public function testInvalidSearchRespectsConfiguredFailureStrategy(): void
    {
        $user = $this->makeUser();
        config(['search-string.fail' => 'no-results']);
        $this->assertSame(0, User::where('uid', $user->uid)->usingSearchString('uid:')->count());
        config(['search-string.fail' => 'all-results']);
        $this->assertSame([$user->uid], User::where('uid', $user->uid)->usingSearchString('uid:')->get()->pluck('uid')->all());
        config(['search-string.fail' => 'exceptions']);
        $this->expectException(InvalidSearchStringException::class);
        User::usingSearchString('uid:');
    }

    public function testDateRangesKeepTheirOwnAndOrGrouping(): void
    {
        config(['search-string.fail' => 'exceptions']);
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeUser();
        $a->forceFill(['register_at' => '2024-06-01 12:00:00'])->save();
        $b->forceFill(['register_at' => '2024-06-02 12:00:00'])->save();
        $c->forceFill(['register_at' => '2024-06-03 12:00:00'])->save();
        $ids = [$a->uid, $b->uid, $c->uid];
        $this->assertSame([$b->uid], User::whereIn('uid', $ids)
            ->usingSearchString('uid:0 or register_at:2024-06-02')->get()->pluck('uid')->all());
        $this->assertSame([$a->uid, $c->uid], User::whereIn('uid', $ids)
            ->usingSearchString('not register_at:2024-06-02 sort:uid')->get()->pluck('uid')->all());
    }

    public function testRelationshipExistenceCountAndNestedQuery(): void
    {
        config(['search-string.fail' => 'exceptions',
            'search-string.'.User::class.'.columns.players' => ['relationship' => true]]);
        $a = $this->makeUser();
        $b = $this->makeUser();
        Player::factory()->create(['uid' => $a->uid, 'name' => 'SearchAlpha']);
        Player::factory()->create(['uid' => $a->uid, 'name' => 'SearchBeta']);
        Player::factory()->create(['uid' => $b->uid, 'name' => 'SearchOther']);
        $ids = [$a->uid, $b->uid];
        $this->assertSame([$a->uid], User::whereIn('uid', $ids)
            ->usingSearchString('players.name:SearchAlpha')->get()->pluck('uid')->all());
        $this->assertSame([$a->uid], User::whereIn('uid', $ids)
            ->usingSearchString('players:(name:SearchAlpha or name:SearchBeta) >= 2')->get()->pluck('uid')->all());
        $this->assertSame([$b->uid], User::whereIn('uid', $ids)
            ->usingSearchString('not players:(name:SearchAlpha)')->get()->pluck('uid')->all());
    }

    public function testUnlistedColumnsCannotBeInjected(): void
    {
        config(['search-string.fail' => 'exceptions']);
        $this->expectException(InvalidSearchStringException::class);
        User::usingSearchString('password:secret');
    }
}
