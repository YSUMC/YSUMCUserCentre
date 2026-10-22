<?php

namespace Tests\Compatibility;

class HttpTest extends TestCase
{
    public function testLoginBootstrapContainsNestedViewComposerData(): void
    {
        $html = $this->get('/auth/login')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script id="blessing-globals"[^>]*>(.*?)<\/script>/s', $html, $matches));
        $globals = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($globals, 'The browser must receive a configuration object before rendering the login form.');
        $this->assertSame(url('/'), $globals['base_url']);
        $this->assertSame('auth/login', $globals['route']);
        $this->assertSame(option_localized('site_name'), $globals['site_name']);
        $this->assertNotEmpty($globals['site_name']);
        $this->assertNotEmpty($globals['locale']);
        $this->assertStringContainsString(' - '.htmlspecialchars($globals['site_name'], ENT_QUOTES), $html);
        $this->assertStringContainsString('auth/eduroam/login', $html, 'Plugin view composers must also render the campus-login entry.');
    }

    public function testAuthenticatedPageBootstrapAndMenuReceiveComposerData(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);
        $html = $this->get('/user')->assertOk()->getContent();
        $this->assertSame(1, preg_match('/<script id="blessing-globals"[^>]*>(.*?)<\/script>/s', $html, $matches));
        $globals = json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($globals);
        $this->assertSame('user', $globals['route']);
        $this->assertSame(url('/'), $globals['base_url']);
        $this->assertStringContainsString(htmlspecialchars($user->nickname, ENT_QUOTES), $html);
    }

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
