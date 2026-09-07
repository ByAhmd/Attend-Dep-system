<?php

declare(strict_types=1);

namespace Tests\Feature\Invitations;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The listener that turns an invited account into an active one.
 *
 * The event is fired for real rather than the listener called directly, so
 * these also prove the listener is registered - it is picked up by Laravel's
 * event discovery of app/Listeners and is bound nowhere by hand.
 *
 * The two accounts that must not move are the point of the test: an active
 * employee resetting a forgotten password is already active, and a
 * deactivated one must stay deactivated. Deactivation is this system's only
 * way to remove somebody, and a password reset must never undo it.
 */
final class ActivateInvitedEmployeeTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    #[Test]
    public function an_invited_account_becomes_active(): void
    {
        $employee = User::factory()->create([
            'status' => UserStatus::Pending,
            'password' => null,
        ]);

        event(new PasswordReset($employee));

        $this->assertSame(UserStatus::Active, $employee->fresh()->status);
    }

    #[Test]
    public function an_active_account_stays_active(): void
    {
        $employee = $this->makeEmployee();

        event(new PasswordReset($employee));

        $this->assertSame(UserStatus::Active, $employee->fresh()->status);
    }

    #[Test]
    public function a_deactivated_account_stays_deactivated(): void
    {
        $employee = $this->makeEmployee(status: UserStatus::Inactive);

        event(new PasswordReset($employee));

        $this->assertSame(UserStatus::Inactive, $employee->fresh()->status);
    }
}
