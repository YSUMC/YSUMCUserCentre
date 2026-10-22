<?php

namespace Tests\Compatibility;

use App\Models\Player;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use LittleSkin\YggdrasilConnect\Listeners\OnPlayerAdded;
use LittleSkin\YggdrasilConnect\Models\AccessToken;
use LittleSkin\YggdrasilConnect\Models\Profile;

class YggdrasilTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $client = app(ClientRepository::class)->createPersonalAccessGrantClient('PHP85 Ygg Test', 'users');
        config(['passport.personal_access_client.id' => $client->id]);
        option(['ygg_disable_authserver' => false]);
    }

    public function testAuthenticateRefreshJoinSignedProfileAndInvalidate(): void
    {
        $user = $this->makeUser();
        $player = Player::create(['uid' => $user->uid, 'name' => 'T85'.bin2hex(random_bytes(5)), 'tid_skin' => 0, 'tid_cape' => 0]);
        (new OnPlayerAdded)->handle($player);
        $uuid = Profile::getUuidFromPlayer($player);
        $base = '/api/yggdrasil/';
        $credentials = ['username' => $user->email, 'password' => 'Only-Test-Password-85', 'clientToken' => 'php85-client', 'requestUser' => true];
        $login = $this->postJson($base.'authserver/authenticate', $credentials)->assertOk()
            ->assertJsonPath('selectedProfile.id', $uuid)->assertJsonPath('clientToken', 'php85-client')->json();
        $this->postJson($base.'authserver/validate', ['accessToken' => $login['accessToken']])->assertNoContent();
        $refreshed = $this->postJson($base.'authserver/refresh', ['accessToken' => $login['accessToken'], 'clientToken' => 'php85-client'])
            ->assertOk()->assertJsonPath('selectedProfile.id', $uuid)->json();
        $this->postJson($base.'authserver/validate', ['accessToken' => $login['accessToken']])->assertForbidden();
        $this->postJson($base.'sessionserver/session/minecraft/join', [
            'accessToken' => $refreshed['accessToken'], 'selectedProfile' => str_repeat('0', 32), 'serverId' => 'php85-session',
        ])->assertForbidden();
        $serverId = 'php85-'.bin2hex(random_bytes(8));
        $this->postJson($base.'sessionserver/session/minecraft/join', [
            'accessToken' => $refreshed['accessToken'], 'selectedProfile' => $uuid, 'serverId' => $serverId,
        ])->assertNoContent();
        $profile = $this->getJson($base.'sessionserver/session/minecraft/hasJoined?'.http_build_query(['username' => $player->name, 'serverId' => $serverId]))
            ->assertOk()->assertJsonPath('id', $uuid)->json();
        $textures = $profile['properties'][0];
        $public = openssl_pkey_get_details(openssl_pkey_get_private(option('ygg_private_key')))['key'];
        $this->assertSame(1, openssl_verify($textures['value'], base64_decode($textures['signature']), $public, OPENSSL_ALGO_SHA1));
        $this->getJson($base.'sessionserver/session/minecraft/hasJoined?'.http_build_query(['username' => $player->name, 'serverId' => 'missing']))->assertNoContent();
        $this->postJson($base.'authserver/invalidate', ['accessToken' => $refreshed['accessToken']])->assertNoContent();
        $this->postJson($base.'authserver/validate', ['accessToken' => $refreshed['accessToken']])->assertForbidden();
        $this->postJson($base.'authserver/authenticate', array_replace($credentials, ['password' => 'wrong']))->assertForbidden();
    }

    public function testExpiredTokenCannotJoinAndBoundProfileCannotBeStolen(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser();
        $player = Player::create(['uid' => $other->uid, 'name' => 'T85'.bin2hex(random_bytes(5)), 'tid_skin' => 0, 'tid_cape' => 0]);
        (new OnPlayerAdded)->handle($player);
        $token = AccessToken::create($user);
        DB::table('oauth_access_tokens')->where('id', $token->id)->update(['expires_at' => now()->subDay()]);
        $expired = new AccessToken($token->jwt);
        $this->assertFalse($expired->canJoinServer());
        $this->assertIsBool($expired->isExpired2());
        $this->postJson('/api/yggdrasil/authserver/refresh', ['accessToken' => $token->jwt, 'selectedProfile' => ['id' => Profile::getUuidFromPlayer($player), 'name' => $player->name]])->assertForbidden();
        $this->postJson('/api/yggdrasil/authserver/validate', ['accessToken' => 'malformed'])->assertForbidden();
    }
}
