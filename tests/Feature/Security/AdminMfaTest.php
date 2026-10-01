<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Two-factor authentication: required of administrators, never offered to
 * employees, and the secret unreadable at rest.
 *
 * Filament decides whether the set-up middleware guards a panel's routes
 * when the routes are REGISTERED, so the requirement must be in the
 * environment before the application boots - a config() call inside a
 * test arrives after the decision was made and proves nothing. This class
 * therefore boots its application with the requirement on, the way
 * production boots; the rest of the suite, booted with phpunit.xml's off,
 * is itself the proof that the switch stands the requirement down.
 */
final class AdminMfaTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        // Before parent::setUp(), which is what creates the application:
        // all three channels, because Laravel's Env reads whichever of
        // them answers first.
        putenv('ATTENDANCE_REQUIRE_ADMIN_MFA=true');
        $_ENV['ATTENDANCE_REQUIRE_ADMIN_MFA'] = 'true';
        $_SERVER['ATTENDANCE_REQUIRE_ADMIN_MFA'] = 'true';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // Back to the suite's default, so the classes that run after this
        // one boot the panel they expect.
        putenv('ATTENDANCE_REQUIRE_ADMIN_MFA=false');
        $_ENV['ATTENDANCE_REQUIRE_ADMIN_MFA'] = 'false';
        $_SERVER['ATTENDANCE_REQUIRE_ADMIN_MFA'] = 'false';
    }

    #[Test]
    public function both_panels_offer_the_authenticator_app_provider(): void
    {
        foreach (['admin', 'employee'] as $panel) {
            $this->assertNotSame(
                [],
                Filament::getPanel($panel)->getMultiFactorAuthenticationProviders(),
                "The {$panel} panel lost its second factor; an enrolled administrator would sign in there unchallenged.",
            );
        }
    }

    #[Test]
    public function an_administrator_without_an_authenticator_is_sent_to_set_up(): void
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());

        $response = $this->get('/admin');

        $response->assertRedirect();
        $this->assertStringContainsString(
            'multi-factor-authentication',
            (string) $response->headers->get('Location'),
        );
    }

    #[Test]
    public function an_employee_is_never_required_to_enrol(): void
    {
        Filament::setCurrentPanel('employee');
        $this->actingAs($this->makeEmployee());

        $this->get('/')->assertOk();
    }

    #[Test]
    public function the_secret_and_the_recovery_codes_are_encrypted_at_rest(): void
    {
        $admin = $this->makeAdmin();

        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');
        $admin->saveAppAuthenticationRecoveryCodes(['code-one', 'code-two']);

        $stored = DB::table('users')->where('id', $admin->id)->first();

        $this->assertIsString($stored->app_authentication_secret);
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $stored->app_authentication_secret);
        $this->assertIsString($stored->app_authentication_recovery_codes);
        $this->assertStringNotContainsString('code-one', $stored->app_authentication_recovery_codes);

        $fresh = User::query()->findOrFail($admin->id);

        $this->assertSame('JBSWY3DPEHPK3PXP', $fresh->getAppAuthenticationSecret());
        $this->assertSame(['code-one', 'code-two'], $fresh->getAppAuthenticationRecoveryCodes());
    }

    #[Test]
    public function the_secret_never_leaves_through_serialisation(): void
    {
        $admin = $this->makeAdmin();
        $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP');

        $serialised = $admin->fresh()?->toArray();

        $this->assertIsArray($serialised);
        $this->assertArrayNotHasKey('app_authentication_secret', $serialised);
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $serialised);
    }
}
