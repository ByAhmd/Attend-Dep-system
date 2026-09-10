<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use App\Support\Filament\PanelAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
    public function a_blank_setting_leaves_no_route_to_an_administrators_own_access(): void
    {
        // Stated here so it is a fact of the suite and not a surprise on a
        // Sunday: with no address pinned, every reserved act is unavailable
        // to everybody, and administrators are the accounts that reserve
        // affects. Nobody can deactivate one, give one a new password,
        // demote one or delete one from the interface at all - the remedy
        // is SUPER_ADMIN_EMAIL on the server, and `app:create-admin` still
        // makes a working administrator from a terminal.
        config(['admin.super_admin_email' => '']);

        $admin = $this->makeAdmin('someone@company.test');
        $colleague = $this->makeAdmin('another@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $gate = Gate::forUser($admin);

        $this->assertFalse($gate->allows('manageAccess', $colleague));
        $this->assertFalse($gate->allows('manageRole', $colleague));
        $this->assertFalse($gate->allows('delete', $colleague));

        // The employees are untouched by any of it.
        $this->assertTrue($gate->allows('manageAccess', $employee));
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

        // Every power an ordinary administrator has over an employee, they
        // keep.
        $this->assertTrue(Gate::forUser($admin)->allows('manageAccess', $employee));
        $this->assertTrue(Gate::forUser($admin)->allows('create', User::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $employee));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', User::class));
    }

    #[Test]
    public function an_ordinary_administrator_cannot_deactivate_or_reset_a_colleague(): void
    {
        // The rule stands on its own: one administrator must not be able to
        // shut another out, whoever the second one happens to be.
        $this->owner();
        $admin = $this->makeAdmin('someone@company.test');
        $colleague = $this->makeAdmin('another@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $gate = Gate::forUser($admin);

        $this->assertFalse($gate->allows('manageAccess', $colleague));
        $this->assertFalse($gate->allows('manageAccess', $admin));
        $this->assertTrue($gate->allows('manageAccess', $employee));

        // Reading and renaming a colleague are untouched: this rule is
        // about access, not about the account.
        $this->assertTrue($gate->allows('view', $colleague));
        $this->assertTrue($gate->allows('update', $colleague));
    }

    #[Test]
    public function the_super_administrator_manages_every_other_administrators_access(): void
    {
        $owner = $this->owner();
        $admin = $this->makeAdmin('someone@company.test');
        $employee = $this->makeEmployee('sara@company.test');

        $gate = Gate::forUser($owner);

        $this->assertTrue($gate->allows('manageAccess', $admin));
        $this->assertTrue($gate->allows('manageAccess', $employee));
        $this->assertFalse($gate->allows('manageAccess', $owner));
    }

    #[Test]
    public function the_designated_account_is_reached_by_nobody_whatever_its_role_column_says(): void
    {
        // The colleague rule and the pinned rule overlap on an ordinary
        // administrator's screen, so this proves the second one is still
        // load bearing: even an actor who may manage administrators - the
        // super administrator is the only one - is refused this account.
        $owner = $this->owner(UserRole::Employee);
        $admin = $this->makeAdmin('someone@company.test');

        $this->assertFalse(Gate::forUser($owner)->allows('manageAccess', $owner));
        $this->assertFalse(Gate::forUser($admin)->allows('manageAccess', $owner));
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
    public function the_command_states_the_whole_cost_of_a_blank_setting(): void
    {
        // The one place an operator reads to find out what a blank setting
        // costs. It used to cost four acts; it now also costs every route
        // to another administrator's access, and a sentence that stopped at
        // the old four would send somebody looking for a reset button that
        // is not there.
        config(['admin.super_admin_email' => null]);

        $this->assertSame(0, Artisan::call('app:super-admin'));

        $output = Artisan::output();

        foreach ([
            'deleted, restored, promoted or demoted',
            'deactivated or reactivated',
            'given a new password',
            'address corrected',
            'stays off',
            'Employees are unaffected',
        ] as $phrase) {
            $this->assertStringContainsString($phrase, $output);
        }
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
