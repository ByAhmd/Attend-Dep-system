<?php

declare(strict_types=1);

namespace Tests\Unit\Deployment;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The promises the deployment makes to a host with PHP, MySQL and nothing
 * else - no Node, no build step, no hand-made SQL.
 *
 * These are asserted rather than trusted because every one of them fails
 * silently: a stale theme renders an unstyled panel, a missing manifest
 * throws only when a page is opened, and an npm call in the deploy script
 * fails on a server nobody can debug from here.
 */
final class DeploymentConfigurationTest extends TestCase
{
    #[Test]
    public function the_compiled_theme_ships_with_the_code(): void
    {
        // Shared hosting has no Node, so the Vite output is committed. Both
        // files must be present in a fresh checkout or every page is unstyled.
        $manifestPath = base_path('public/build/manifest.json');

        $this->assertFileExists($manifestPath);

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        $this->assertIsArray($manifest);
        $this->assertArrayHasKey('resources/css/filament/theme.css', $manifest);

        $theme = $manifest['resources/css/filament/theme.css']['file'] ?? null;

        $this->assertIsString($theme);
        $this->assertFileExists(base_path('public/build/'.$theme));
    }

    #[Test]
    public function the_built_theme_is_not_ignored_by_git(): void
    {
        $gitignore = (string) file_get_contents(base_path('.gitignore'));

        // A bare /public/build line would quietly undo the guarantee above.
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*\/public\/build\s*$/m',
            $gitignore,
            'public/build must stay committed: the server has no Node to rebuild it.',
        );
    }

    #[Test]
    public function filament_publishes_its_own_assets_into_the_repository(): void
    {
        // Published by `filament:upgrade` on composer install and committed,
        // so the panels work before anyone runs a build step.
        $this->assertFileExists(base_path('public/js/filament/filament/app.js'));
        $this->assertFileExists(base_path('public/css/filament/filament/app.css'));
    }

    #[Test]
    public function the_deploy_script_never_needs_node(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        // Only executed lines matter: the script's error text may well name
        // `npm run build` when telling a developer what to do on their own
        // machine, and that sentence is the opposite of a defect.
        $this->assertDoesNotMatchRegularExpression('/^\s*(npm|node|npx|yarn)\b/m', $script);
    }

    #[Test]
    public function the_deploy_script_installs_migrates_and_caches(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringContainsString('composer', $script);
        $this->assertStringContainsString('--no-dev', $script);
        $this->assertStringContainsString('migrate --force', $script);
        $this->assertStringContainsString('db:seed --force', $script);
        $this->assertStringContainsString('config:cache', $script);
        $this->assertStringContainsString('route:cache', $script);
        $this->assertStringContainsString('view:cache', $script);
        $this->assertStringContainsString('filament:optimize', $script);
        $this->assertStringContainsString('artisan up', $script);

        // Nothing that would wipe a live database or plant demo credentials.
        $this->assertStringNotContainsString('migrate:fresh', $script);
        $this->assertStringNotContainsString('migrate:refresh', $script);
        $this->assertStringNotContainsString('--class=DemoDataSeeder', $script);
    }

    #[Test]
    public function the_deploy_script_refuses_to_run_without_an_environment(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringContainsString('set -euo pipefail', $script);
        $this->assertStringContainsString('.env', $script);
        $this->assertStringContainsString('APP_KEY', $script);
        $this->assertStringContainsString('public/build/manifest.json', $script);
    }

    #[Test]
    public function continuous_integration_catches_a_stale_theme(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('git diff --exit-code -- public/build', $workflow);
        $this->assertStringContainsString("php: ['8.3', '8.4']", $workflow);
    }

    #[Test]
    public function no_database_dump_is_carried_in_the_repository(): void
    {
        // The migrations are the schema. A dump would be a second copy of it
        // that drifts, and it would carry a password hash into version control.
        $this->assertSame([], glob(base_path('*.sql')) ?: []);
        $this->assertSame([], glob(base_path('database/*.sql')) ?: []);
    }

    #[Test]
    public function the_application_timezone_is_frozen_to_riyadh(): void
    {
        $this->assertStringContainsString(
            "'timezone' => env('APP_TIMEZONE', 'Asia/Riyadh')",
            (string) file_get_contents(base_path('config/app.php')),
        );
    }

    #[Test]
    public function the_deployment_guide_documents_the_hosting_steps(): void
    {
        $deployment = (string) file_get_contents(base_path('DEPLOYMENT.md'));

        $this->assertStringContainsString('scripts/deploy.sh', $deployment);
        $this->assertStringContainsString('Hostinger', $deployment);
        $this->assertStringContainsString('document root', $deployment);
        $this->assertStringContainsString('app:create-admin', $deployment);
    }
}
