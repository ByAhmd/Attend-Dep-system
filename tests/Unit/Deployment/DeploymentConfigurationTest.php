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
        $this->assertStringContainsString('public/build/manifest.json', $script);
    }

    #[Test]
    public function the_first_deployment_needs_no_key_of_its_own(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        // A fresh clone has no vendor/, so artisan cannot run until Composer
        // has. Requiring APP_KEY before that made the first deploy impossible:
        // the key must be generated after the install, and only when absent.
        $composerAt = strpos($script, 'install --no-dev');
        $keyAt = strpos($script, 'key:generate --force');

        $this->assertIsInt($composerAt);
        $this->assertIsInt($keyAt);
        $this->assertLessThan($keyAt, $composerAt, 'Composer must run before the key is generated.');

        $this->assertMatchesRegularExpression(
            '/if grep -qE .\^APP_KEY=base64:\.\+. \.env; then/',
            $script,
            'An existing key must never be regenerated: that signs every session out.',
        );
    }

    #[Test]
    public function the_deploy_script_never_fails_silently(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        // Shared hosting ships display_errors=Off for the CLI, which turns a
        // fatal error into an empty screen and a returned prompt.
        $this->assertStringContainsString('display_errors=stderr', $script);
        $this->assertStringContainsString('vendor/autoload.php', $script);
    }

    #[Test]
    public function continuous_integration_catches_a_stale_theme(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        $this->assertStringContainsString('git diff --exit-code -- public/build', $workflow);
        $this->assertStringContainsString("php: ['8.3', '8.4']", $workflow);
    }

    #[Test]
    public function the_suite_does_not_depend_on_a_developers_env_file(): void
    {
        $phpunit = (string) file_get_contents(base_path('phpunit.xml'));

        // CI has no .env. Anything environment-sensitive that the tests read
        // must be pinned here, or a test passes on a laptop and fails on a
        // build server for reasons that have nothing to do with the change.
        foreach (['APP_KEY', 'APP_URL', 'APP_ENV', 'APP_LOCALE', 'APP_TIMEZONE', 'MAIL_MAILER', 'DB_CONNECTION', 'DB_DATABASE'] as $key) {
            $this->assertMatchesRegularExpression(
                '/<env name="'.$key.'" value=/',
                $phpunit,
                "phpunit.xml must pin {$key}, otherwise the suite behaves differently in CI.",
            );
        }
    }

    #[Test]
    public function production_is_only_deployed_after_the_whole_suite_passes(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        // The deploy job must depend on the test job and must never run for a
        // pull request or a branch that is not master.
        $this->assertStringContainsString('needs: validate', $workflow);
        $this->assertStringContainsString("github.ref == 'refs/heads/master'", $workflow);
        $this->assertStringContainsString("github.event_name == 'push'", $workflow);

        // Off until the repository is deliberately configured for it.
        $this->assertStringContainsString("vars.DEPLOY_ENABLED == 'true'", $workflow);

        // It deploys by running the same script a human would.
        $this->assertStringContainsString('bash scripts/deploy.sh', $workflow);
    }

    #[Test]
    public function a_deployment_is_never_cancelled_half_way(): void
    {
        $workflow = (string) file_get_contents(base_path('.github/workflows/ci.yml'));

        // Cancelling a run on master could interrupt a migration, so only
        // pull-request runs are superseded.
        $this->assertStringContainsString(
            "cancel-in-progress: \${{ github.ref != 'refs/heads/master' }}",
            $workflow,
        );

        $this->assertStringContainsString('group: production-deploy', $workflow);
        $this->assertStringContainsString('cancel-in-progress: false', $workflow);
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

    /**
     * A deploy log is written by a machine and read by whoever asks for it.
     *
     * The verbose form of app:super-admin prints the configured address, the
     * display name and the account's role and status, because a person at a
     * terminal asked it who that account is. A deploy script asks a different
     * question - is one configured at all - and the --check form answers that
     * one with a verdict and no value, so nothing about the account is
     * written anywhere a build log can be read from.
     */
    #[Test]
    public function the_deploy_script_never_prints_who_the_super_administrator_is(): void
    {
        $script = (string) file_get_contents(base_path('scripts/deploy.sh'));

        $this->assertStringNotContainsString('app:super-admin || true', $script);
        $this->assertStringContainsString('app:super-admin --check', $script);
    }

    /**
     * The employee's own error pages.
     *
     * Laravel's are `<html lang="en">` with no direction, and their message
     * is whatever the exception carried - an English sentence written for a
     * developer. A stale attachment link is an ordinary way for a signed-in
     * employee to reach one, so the pages have to exist here, in both
     * languages, and they must not depend on the Vite build: the failure
     * page is the last thing that should need the thing that failed.
     */
    #[Test]
    public function the_error_pages_are_the_products_own_and_need_no_build(): void
    {
        foreach (['403', '404', 'layout'] as $view) {
            $this->assertFileExists(base_path("resources/views/errors/{$view}.blade.php"));
        }

        $layout = (string) file_get_contents(base_path('resources/views/errors/layout.blade.php'));

        $this->assertStringNotContainsString('@vite', $layout);
        $this->assertStringContainsString('dir=', $layout);
    }
}
