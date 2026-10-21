<?php

namespace Tests\Compatibility;

use App\OAuth\JanusClient;
use Illuminate\Support\Once;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class OAuthTest extends TestCase
{
    public function testPluginClientCommandPreservesOwnerAndRedirect(): void
    {
        $owner = $this->makeUser();
        config(['passport.personal_access_client.id' => null]);
        option(['ygg_connect_server_url' => 'http://localhost:13850/api/janus']);
        $this->artisan('yggc:create-personal-access-client', ['--name' => 'PHP85 Console Client', '--owner' => $owner->uid])->assertSuccessful();
        $client = JanusClient::where('name', 'PHP85 Console Client')->firstOrFail();
        $this->assertEquals($owner->uid, $client->user_id);
        $this->assertTrue($client->hasGrantType('personal_access'));
        $this->assertSame('http://localhost:13850/api/janus/callback', $client->redirect);
    }

    public function testUnverifiedUsersCannotManageOAuthClients(): void
    {
        option(['require_verification' => true]);
        $user = $this->makeUser();
        $user->verified = false;
        $user->save();
        $this->actingAs($user, 'web');
        $this->getJson('/oauth/clients')->assertForbidden();
        $this->postJson('/oauth/clients', ['name' => 'Blocked', 'redirect' => 'http://localhost/callback'])->assertForbidden();
    }

    public function testWrongPersonalClientDoesNotSilentlyFallBack(): void
    {
        app(ClientRepository::class)->createPersonalAccessGrantClient('Available but not configured', 'users');
        config(['passport.personal_access_client.id' => 999999999]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Configured personal access client is unavailable');
        $this->makeUser()->createToken('Must fail', ['User.Read']);
    }

    public function testAuthorizationCodeRefreshScopesAndRevocation(): void
    {
        $user = $this->makeUser();
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
            'PHP85 OAuth Test', ['http://127.0.0.1:13859/callback'], true, $user
        );
        $this->assertIsInt($client->id);
        $this->assertSame($client->plainSecret, $client->secret);
        $this->actingAs($user, 'web');
        $page = $this->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->id, 'redirect_uri' => $client->redirect,
            'response_type' => 'code', 'scope' => 'User.Read', 'state' => 'php85-state',
            'prompt' => 'consent',
        ]))->assertOk();
        preg_match('/name="auth_token" value="([^"]+)"/', $page->getContent(), $matches);
        $this->assertNotEmpty($matches[1] ?? null);
        $approval = $this->post('/oauth/authorize', [
            'client_id' => $client->id, 'state' => 'php85-state', 'auth_token' => html_entity_decode($matches[1]),
        ])->assertRedirect();
        parse_str(parse_url($approval->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('php85-state', $query['state']);
        $credentials = ['client_id' => $client->id, 'client_secret' => $client->secret];
        $exchange = $credentials + ['grant_type' => 'authorization_code', 'redirect_uri' => $client->redirect, 'code' => $query['code']];
        $this->postJson('/oauth/token', array_replace($exchange, ['client_secret' => 'wrong']))->assertUnauthorized();
        $token = $this->postJson('/oauth/token', $exchange)->assertOk()->assertJsonStructure(['access_token', 'refresh_token'])->json();
        $this->postJson('/oauth/token', $exchange)->assertStatus(400); // Code is single use.
        $this->bearerGet('/api/user', $token['access_token'])->assertOk()->assertJsonPath('uid', $user->uid);
        $this->bearerGet('/api/players', $token['access_token'])->assertOk()->assertJsonPath('code', 403);
        $fresh = $this->postJson('/oauth/token', $credentials + ['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']])
            ->assertOk()->json();
        $this->bearerGet('/api/user', $token['access_token'])->assertUnauthorized();
        $this->bearerGet('/api/user', $fresh['access_token'])->assertOk();
        Passport::token()->newQuery()->where('user_id', $user->uid)->update(['revoked' => true]);
        $this->bearerGet('/api/user', $fresh['access_token'])->assertUnauthorized();
    }

    public function testLegacyClientSecretVerificationAndRevokedClients(): void
    {
        $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Client Test', ['http://127.0.0.1/callback']);
        $bridge = app(\Laravel\Passport\Bridge\ClientRepository::class);
        $this->assertTrue($bridge->validateClient((string) $client->id, $client->secret, 'authorization_code'));
        $this->assertFalse($bridge->validateClient((string) $client->id, 'wrong', 'authorization_code'));
        $client->revoked = true;
        $client->save();
        Once::flush();
        $this->assertFalse($bridge->validateClient((string) $client->id, $client->secret, 'authorization_code'));
    }

    public function testClientManagementIsOwnedByAuthenticatedUser(): void
    {
        $owner = $this->makeUser();
        $this->actingAs($owner, 'web');
        $client = $this->postJson('/oauth/clients', ['name' => 'PHP85 UI Client', 'redirect' => 'http://127.0.0.1/callback'])
            ->assertCreated()->assertJsonStructure(['id', 'secret', 'redirect'])->json();
        $this->getJson('/oauth/clients')->assertOk()->assertJsonFragment(['id' => $client['id']]);
        $this->actingAs($this->makeUser(), 'web');
        $this->putJson('/oauth/clients/'.$client['id'], ['name' => 'not yours', 'redirect' => 'http://127.0.0.1/other'])->assertNotFound();
        $this->deleteJson('/oauth/clients/'.$client['id'])->assertNotFound();
        $this->actingAs($owner, 'web');
        $this->deleteJson('/oauth/clients/'.$client['id'])->assertNoContent();
        $this->assertTrue(JanusClient::find($client['id'])->revoked);
    }

    private function bearerGet(string $path, string $jwt)
    {
        auth()->forgetGuards();
        Once::flush();
        return $this->getJson($path, ['Authorization' => 'Bearer '.$jwt]);
    }
}
