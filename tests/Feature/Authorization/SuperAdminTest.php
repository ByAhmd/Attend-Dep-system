<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Filament\PanelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The super administrator: designated in .env, and outranking the database.
 *
 * The owner asked for something that cannot be undone by anyone from
 * anywhere except themselves, so the designation is not a row. It is
 * SUPER_ADMIN_EMAIL on the server, read through config, and whoever holds
 * that address is the super administrator whatever `users.role`,
 * `users.status` and `users.deleted_at` say. These tests set the address
 * and then do their best to take the account away through every column
 * there is.
 */
final class SuperAdminTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string OWNER_EMAIL = 'owner@company.test';

    #[Test]
    public function the_super_administrator_is_whoever_holds_the_configured_address(): void
    {
        // Demoted to employee and switched off in the database - the two
        // columns an attacker with phpMyAdmin would reach for.
        $owner = $this->owner(UserRole::Employee, UserStatus::Inactive);

        $this->assertTrue($owner->isSuperAdmin());
        $this->assertTrue($owner->isAdmin());
        $this->assertTrue($owner->isActive());
    }

    #[Test]
    public function everybody_else_is_not(): void
    {
        $this->owner();
        $admin = $this->makeAdmin('someone@company.test');

        $this->assertFalse($admin->isSuperAdmin());
        $this->assertTrue($admin->isAdmin());
    }

    #[Test]
    public function the_address_is_matched_ignoring_case_and_surrounding_space(): void
    {
        // Email addresses are not case sensitive in practice, and a stray
        // space in a .env line is invisible in an editor.
        config(['admin.super_admin_email' => '  Owner@Company.TEST  ']);

        $owner = $this->makeAdmin(self::OWNER_EMAIL);

        $this->assertTrue($owner->isSuperAdmin());
    }

    #[Test]
    public function nobody_is_the_super_administrator_when_the_setting_is_blank(): void
    {
        config(['admin.super_admin_email' => '']);

        $admin = $this->makeAdmin(self::OWNER_EMAIL);

        $this->assertNull(User::superAdminEmail());
        $this->assertFalse($admin->isSuperAdmin());
        $this->assertFalse(Gate::forUser($admin)->allows('delete', $this->makeEmployee()));
    }

    #[Test]
    public function the_super_administrator_reaches_the_admin_panel_while_the_database_says_inactive_employee(): void
    {
        $owner = $this->owner(UserRole::Employee, UserStatus::Inactive);

        $this->assertTrue(PanelAccess::canAccess($owner, PanelAccess::ADMIN_PANEL_ID));

        // Past EnsureAccountIsActive, which signs an inactive account out,
        // and past Filament's canAccessPanel(), which refuses an employee.
        $this->actingAs($owner)->get('/admin')->assertOk();
        $this->assertAuthenticatedAs($owner);
    }

    #[Test]
    public function a_deleted_at_written_straight_into_the_database_does_not_lock_the_super_administrator_out(): void
    {
        // deleted_at is a third column with the power to remove an account,
        // so it gets the same treatment as the other two: the row named by
        // SUPER_ADMIN_EMAIL is never hidden.
        $owner = $this->owner();

        DB::table('users')->where('id', $owner->id)->update(['deleted_at' => now()]);

        $this->assertNotNull(User::query()->find($owner->getKey()));
        $this->assertNotNull(Auth::getProvider()->retrieveById($owner->getKey()));
        $this->actingAs($owner->fresh())->get('/admin')->assertOk();
    }

    #[Test]
    public function an_ordinary_employee_is_hidden_by_the_same_scope(): void
    {
        // The exception is one address wide; everybody else soft deletes
        // exactly as Laravel intends.
        $this->owner();
        $employee = $this->makeEmployee('sara@company.test');

        $employee->delete();

        $this->assertNull(User::query()->find($employee->getKey()));
        $this->assertNotNull(User::withTrashed()->find($employee->getKey()));
        $this->assertCount(1, User::onlyTrashed()->get());
    }

    #[Test]
    public function no_administrator_can_deactivate_demote_or_reset_the_password_of_the_super_administrator(): void
    {
        $owner = $this->owner();
        $admin = $this->makeAdmin('someone@company.test');

        // manageAccess is the one gate behind the status toggle and the
        // password reset; manageRole is the one behind the role select.
        foreach ([$admin, $owner] as $actor) {
            $gate = Gate::forUser($actor);

            $this->assertFalse($gate->allows('manageAccess', $owner));
            $this->assertFalse($gate->allows('manageRole', $owner));
        }
    }

    #[Test]
    public function nobody_can_delete_the_super_administrator(): void
    {
        $owner = $this->owner();
        $admin = $this->makeAdmin('someone@company.test');

        foreach ([$admin, $owner] as $actor) {
            $gate = Gate::forUser($actor);

            $this->assertFalse($gate->allows('delete', $owner));
            $this->assertFalse($gate->allows('forceDelete', $owner));
        }
    }

    #[Test]
    public function an_ordinary_administrator_cannot_delete_or_restore_anybody(): void
    {
        $this->owner();
        $admin = $this->makeAdmin('someone@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $gate = Gate::forUser($admin);

        $this->assertFalse($gate->allows('delete', $employee));
        $this->assertFalse($gate->allows('restore', $employee));
        $this->assertFalse($gate->allows('forceDelete', $employee));
        $this->assertFalse($gate->allows('deleteAny', User::class));
    }

    #[Test]
    public function only_the_super_administrator_appoints_or_removes_an_administrator(): void
    {
        $owner = $this->owner();
        $admin = $this->makeAdmin('someone@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $this->assertTrue(Gate::forUser($owner)->allows('manageRole', $employee));
        $this->assertTrue(Gate::forUser($owner)->allows('manageRole', $admin));
        $this->assertFalse(Gate::forUser($admin)->allows('manageRole', $employee));

        // Everything else an ordinary administrator had, they keep.
        $this->assertTrue(Gate::forUser($admin)->allows('manageAccess', $employee));
        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $employee));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
    }

    #[Test]
    public function the_super_administrator_deletes_and_restores(): void
    {
        $owner = $this->owner();
        $employee = $this->makeEmployee('sara@company.test');

        $this->assertTrue(Gate::forUser($owner)->allows('delete', $employee));

        $employee->delete();

        $this->assertTrue(Gate::forUser($owner)->allows('restore', $employee));
    }

    #[Test]
    public function the_super_administrator_still_cannot_change_their_own_role_or_status(): void
    {
        // Moot - the columns do not decide who they are - but the rule that
        // nobody edits their own access on this screen stays honest.
        $owner = $this->owner();

        $this->assertFalse(Gate::forUser($owner)->allows('manageAccess', $owner));
        $this->assertFalse(Gate::forUser($owner)->allows('manageRole', $owner));
    }

    #[Test]
    public function the_command_reports_who_the_super_administrator_is(): void
    {
        $this->owner();

        $this->artisan('app:super-admin')
            ->expectsOutputToContain(self::OWNER_EMAIL)
            ->assertSuccessful();
    }

    #[Test]
    public function the_command_fails_when_the_configured_address_has_no_account(): void
    {
        // The silent misconfiguration: a typo leaves a deployment where no
        // account can be deleted or promoted and nobody is told why.
        config(['admin.super_admin_email' => 'typo@company.test']);

        $this->artisan('app:super-admin')
            ->expectsOutputToContain('typo@company.test')
            ->assertFailed();
    }

    #[Test]
    public function the_command_says_so_when_no_super_administrator_is_configured(): void
    {
        config(['admin.super_admin_email' => null]);

        $this->artisan('app:super-admin')
            ->expectsOutputToContain('No super administrator is configured.')
            ->assertSuccessful();
    }

    #[Test]
    public function the_check_form_confirms_the_setting_without_naming_the_account(): void
    {
        // The deployment script runs this form, and its output is a log
        // rather than a terminal: the exit code is all an unattended check
        // needs, and the address is the part that must not travel.
        $this->owner();

        $this->artisan('app:super-admin', ['--check' => true])
            ->expectsOutputToContain('Super administrator: configured.')
            ->doesntExpectOutputToContain(self::OWNER_EMAIL)
            ->assertSuccessful();
    }

    #[Test]
    public function the_check_form_fails_on_the_misconfiguration_without_printing_the_address(): void
    {
        config(['admin.super_admin_email' => 'typo@company.test']);

        $this->artisan('app:super-admin', ['--check' => true])
            ->expectsOutputToContain('MISCONFIGURED')
            ->doesntExpectOutputToContain('typo@company.test')
            ->assertFailed();
    }

    #[Test]
    public function the_check_form_reports_nothing_configured_and_still_succeeds(): void
    {
        // Same exit code as the verbose form: a blank setting is a thing to
        // read and fix, never a reason to fail a deployment.
        config(['admin.super_admin_email' => null]);

        $this->artisan('app:super-admin', ['--check' => true])
            ->expectsOutputToContain('Super administrator: not configured')
            ->doesntExpectOutputToContain('@')
            ->assertSuccessful();
    }

    /**
     * The account named by SUPER_ADMIN_EMAIL, created with whatever role and
     * status the test wants to see ignored.
     */
    private function owner(
        UserRole $role = UserRole::Admin,
        UserStatus $status = UserStatus::Active,
    ): User {
        config(['admin.super_admin_email' => self::OWNER_EMAIL]);

        return User::factory()->create([
            'name' => 'The Owner',
            'email' => self::OWNER_EMAIL,
            'password' => 'secret',
            'role' => $role,
            'status' => $status,
        ]);
    }
}
