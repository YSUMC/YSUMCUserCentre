<?php

namespace Tests\Security;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use SITMinecraft\Launcher\Experience\ExperienceRepository;
use SITMinecraft\Launcher\Database\LauncherSchema;

final class LauncherExperienceTest extends TestCase
{
    private function input(ExperienceRepository|\MUTMC\Launcher\Experience\ExperienceRepository $repository): array
    {
        return ['revision' => $repository->read()['revision'], 'navigation' => ['home' => '1', 'skins' => '0'],
            'pages' => [['id' => 'map1', 'title' => 'Map', 'kind' => 'map', 'url' => 'https://map.example/', 'enabled' => '1']],
            'activities' => [['id' => 'activity', 'title' => 'Meet', 'kind' => 'checkin', 'starts_at' => '2026-11-04T20:00', 'ends_at' => '2026-11-04T21:00', 'enabled' => '1']]];
    }

    public function testBrandIsolationAndPublicProjection(): void
    {
        $ysu = new ExperienceRepository; $mut = new \MUTMC\Launcher\Experience\ExperienceRepository;
        DB::table(LauncherSchema::SETTINGS)->insert(['name' => 'latest_version', 'value' => '1.1.3']);
        $ysu->save($this->input($ysu));
        $this->assertFalse($ysu->read()['navigation']['skins']);
        $this->assertTrue($mut->read()['navigation']['skins']);
        $this->assertSame([], $mut->read()['activities']);
        $published = $ysu->published(Request::create('/'));
        $this->assertSame('2026-11-04T12:00:00+00:00', $published['activities'][0]['starts_at']);
        $this->assertArrayNotHasKey('enabled', $published['pages'][0]);
        $this->assertArrayNotHasKey('revision', $published);
        $this->assertSame('1.1.3', DB::table(LauncherSchema::SETTINGS)->where('name','latest_version')->value('value'));
        $input = $this->input($ysu); $input['pages'][0]['enabled'] = '0'; $input['activities'][0]['enabled'] = '0';
        $ysu->save($input);
        $this->assertSame([], $ysu->published(Request::create('/'))['pages']);
        $this->assertSame([], $ysu->published(Request::create('/'))['activities']);
    }

    public function testLegacyMapIsEditableAndRemovingItDoesNotRestoreIt(): void
    {
        $repository = new ExperienceRepository;
        DB::table(LauncherSchema::SETTINGS)->insert(['name' => 'cloud_map_url', 'value' => 'https://legacy.example/']);
        $this->assertSame('legacy-map', $repository->read()['pages'][0]['id']);
        $input = $this->input($repository); $input['pages'] = [];
        $repository->save($input);
        $this->assertSame([], $repository->read()['pages']);
    }

    public function testRejectsStaleWritesWithoutLosingSavedConfiguration(): void
    {
        $repository = new ExperienceRepository;
        $input = $this->input($repository); $repository->save($input);
        try { $repository->save($input); $this->fail('Stale save accepted'); }
        catch (ValidationException $e) { $this->assertArrayHasKey('revision',$e->errors()); }
        $this->assertCount(1,$repository->read()['pages']);
    }

    public function testRejectsDangerousUrlsAndInvalidSchedulesForBothBrands(): void
    {
        foreach ([new ExperienceRepository, new \MUTMC\Launcher\Experience\ExperienceRepository] as $repository) {
            foreach (['javascript:alert(1)', 'http://plain.example', 'https://user:secret@example.com'] as $url) {
                $input = $this->input($repository); $input['pages'][0]['url'] = $url;
                try { $repository->save($input); $this->fail('Unsafe URL accepted'); }
                catch (ValidationException $e) { $this->assertArrayHasKey('pages.0.url',$e->errors()); }
            }
            $input = $this->input($repository); $input['activities'][0]['ends_at'] = '2026-11-03T20:00';
            try { $repository->save($input); $this->fail('Negative duration accepted'); }
            catch (ValidationException $e) { $this->assertArrayHasKey('activities.0.ends_at',$e->errors()); }
        }
    }

    public function testManagementRoutesHaveIndependentWritePolicy(): void
    {
        $policy = $this->app->make(\SITMinecraft\Launcher\Access\BackendPolicy::class);
        $this->assertSame('ysumc_experience',$policy->capability('admin/launcher/experience'));
        $this->assertSame('mutmc_experience',$policy->capability('admin/launcher/mutmc/experience'));
        $reader = $this->user();
        $request = Request::create('/admin/launcher/experience', 'POST'); $request->setUserResolver(fn () => $reader);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        (new \SITMinecraft\Launcher\Http\BackendGuard($policy))->handle($request,fn () => $this->fail('Unauthorized config write'), 'admin');
    }

    public function testBothAdminFormsRenderSavedValuesAndEscapeUntrustedText(): void
    {
        foreach (['sitmc-launcher' => new ExperienceRepository, 'mutmc-launcher' => new \MUTMC\Launcher\Experience\ExperienceRepository] as $plugin => $repository) {
            $input = $this->input($repository);
            $input['activities'][0]['title'] = '<img src=x onerror=alert(1)>';
            $repository->save($input);
            $root = base_path('plugins/'.$plugin.'/views/admin/');
            $templates = ['admin.base' => '{% block content %}{% endblock %}'];
            foreach (['experience', 'experience-row', 'distribution-nav'] as $name) {
                $templates[$plugin.'::admin.'.$name] = file_get_contents($root.$name.'.twig');
            }
            $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader($templates), ['autoescape' => 'html']);
            foreach (['route', 'trans', 'csrf_field', 'asset'] as $function) {
                $twig->addFunction(new \Twig\TwigFunction($function, fn ($key = '') => $key));
            }
            $html = $twig->render($plugin.'::admin.experience', [
                'document' => $repository->read(), 'navigationLabels' => $repository::LABELS,
                'distributionBrand' => $repository::BRAND, 'brandLabel' => strtoupper($repository::BRAND),
                'adminRoutePrefix' => $plugin.'.admin.', 'adminIndexRoute' => $plugin.'.admin.index',
                'errors' => new \Illuminate\Support\MessageBag,
            ]);
            $this->assertStringContainsString('action="'.$plugin.'.admin.experience.save"', $html);
            $this->assertStringContainsString('value="2026-11-04T20:00"', $html);
            $this->assertStringContainsString('value="&lt;img src=x onerror=alert(1)&gt;"', $html);
            $this->assertStringNotContainsString('<img src=x', $html);
            $this->assertStringContainsString('plugins/'.$plugin.'/assets/experience.js', $html);
        }
    }
}
