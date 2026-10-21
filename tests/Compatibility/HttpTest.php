<?php

namespace Tests\Compatibility;

class HttpTest extends TestCase
{
    public function testPublicPagesRenderWithTwigThree(): void
    {
        foreach (['/', '/auth/login', '/auth/eduroam/login'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }
        $this->get('/skinlib')->assertRedirect('/auth/login');
        $this->getJson('/api/launcher/manifest')->assertOk();
        $this->getJson('/api/yggdrasil')->assertOk();
    }

    public function testPasswordLoginAndUserPages(): void
    {
        $user = $this->makeUser();
        $this->postJson('/auth/login', ['identification' => $user->email, 'password' => 'Wrong-Password-85'])
            ->assertOk()->assertJsonPath('code', 1);
        $this->postJson('/auth/login', ['identification' => $user->email, 'password' => 'Only-Test-Password-85'])
            ->assertOk()->assertJsonPath('code', 0);
        $this->assertAuthenticatedAs($user);
        foreach (['/user', '/user/player', '/user/closet', '/user/oauth/manage', '/skinlib'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }
    }

    public function testAdminPagesAndUserIsolation(): void
    {
        $this->actingAs($this->makeUser(2));
        foreach (['/admin', '/admin/users', '/admin/players', '/admin/plugins/manage'] as $path) {
            $this->assertSame(200, $this->get($path)->status(), $path);
        }
        $this->actingAs($this->makeUser());
        $this->get('/admin')->assertForbidden();
    }

    public function testAnonymousApisRemainProtected(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
        $this->getJson('/api/players')->assertUnauthorized();
        $this->postJson('/oauth/token', ['grant_type' => 'authorization_code', 'client_id' => '999999999', 'code' => 'invalid'])
            ->assertStatus(401);
    }
}
