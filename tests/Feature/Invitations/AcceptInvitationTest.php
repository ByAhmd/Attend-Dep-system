<?php

declare(strict_types=1);

namespace Tests\Feature\Invitations;

use App\Enums\UserStatus;
use App\Filament\Auth\ResetPassword;
use App\Models\User;
use App\Services\Users\EmployeeInvitationService;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Accepting an invitation, from the link to the first sign-in.
 *
 * An invited account is locked out exactly as a deactivated one is - no
 * password to check and a status that cannot authenticate - so these tests
 * walk the only door it has: the reset screen the link points at. The
 * account becomes active by setting the password and by nothing else, and
 * a link that has been replaced or belongs to a deactivated account must
 * not open that door.
 */
final class AcceptInvitationTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string CHOSEN_PASSWORD = 'my-own-password-1';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    #[Test]
    public function an_invited_account_has_no_password_and_cannot_sign_in(): void
    {
        $employee = $this->invitedEmployee();

        $this->assertNull($employee->password);
        $this->assertFalse(Auth::attempt(['email' => $employee->email, 'password' => 'anything']));

        Livewire::test(Login::class)
            ->fillForm(['email' => $employee->email, 'password' => 'anything'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    #[Test]
    public function an_invited_account_reaches_neither_panel(): void
    {
        $employee = $this->invitedEmployee();

        $this->actingAs($employee)->get('/')->assertRedirect();
        $this->assertGuest();

        $this->actingAs($employee)->get('/admin')->assertRedirect();
        $this->assertGuest();
    }

    #[Test]
    public function setting_a_password_through_the_link_activates_the_account(): void
    {
        $employee = $this->invitedEmployee();

        $this->acceptInvitation($employee, $this->linkFor($employee))
            ->assertHasNoFormErrors()
            ->assertNotified(__('passwords.reset'));

        $employee->refresh();

        $this->assertSame(UserStatus::Active, $employee->status);
        $this->assertTrue(Hash::check(self::CHOSEN_PASSWORD, (string) $employee->password));
        $this->assertTrue(Auth::attempt(['email' => $employee->email, 'password' => self::CHOSEN_PASSWORD]));
    }

    #[Test]
    public function an_activated_employee_signs_in_and_reaches_the_attendance_screen(): void
    {
        $employee = $this->invitedEmployee();

        $this->acceptInvitation($employee, $this->linkFor($employee));

        Livewire::test(Login::class)
            ->fillForm(['email' => $employee->email, 'password' => self::CHOSEN_PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($employee->fresh());
    }

    #[Test]
    public function issuing_a_new_link_stops_the_old_one_working(): void
    {
        $employee = $this->invitedEmployee();

        $stale = $this->linkFor($employee);
        $this->linkFor($employee);

        $this->acceptInvitation($employee, $stale)
            ->assertNotified(__('passwords.token'));

        $employee->refresh();

        $this->assertSame(UserStatus::Pending, $employee->status);
        $this->assertNull($employee->password);
    }

    #[Test]
    public function a_deactivated_account_cannot_let_itself_back_in_with_a_link(): void
    {
        // The token is issued while the account is still pending, so the
        // link is genuine; deactivation between issuing and clicking is
        // what must stop it.
        $employee = $this->invitedEmployee();
        $link = $this->linkFor($employee);

        $employee->forceFill(['status' => UserStatus::Inactive])->save();

        $this->acceptInvitation($employee, $link)
            ->assertNotified(__('passwords.user'));

        $employee->refresh();

        $this->assertSame(UserStatus::Inactive, $employee->status);
        $this->assertNull($employee->password);
    }

    #[Test]
    public function the_admin_panel_offers_no_reset_flow_of_its_own(): void
    {
        // Administrators are created with app:create-admin; the invitation
        // screen belongs to the employee panel alone.
        $this->assertNull(Filament::getPanel('admin')->getRequestPasswordResetUrl());
    }

    private function invitedEmployee(string $email = 'invited@company.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'status' => UserStatus::Pending,
            'password' => null,
        ]);
    }

    private function linkFor(User $employee): string
    {
        return app(EmployeeInvitationService::class)->issueLink($employee);
    }

    /**
     * Open the link and set a password, the way the employee's browser does.
     */
    private function acceptInvitation(User $employee, string $link): Testable
    {
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        return Livewire::test(ResetPassword::class, [
            'email' => $employee->email,
            'token' => $query['token'] ?? null,
        ])
            ->fillForm([
                'password' => self::CHOSEN_PASSWORD,
                'passwordConfirmation' => self::CHOSEN_PASSWORD,
            ])
            ->call('resetPassword');
    }
}
