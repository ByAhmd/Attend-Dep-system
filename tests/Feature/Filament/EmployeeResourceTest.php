<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ListEmployees;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * EmployeeResource: the one screen that creates accounts, invites their
 * owners, changes what they may do, and hands an existing account a new
 * password.
 *
 * The rules under test are the business rules of section 3: accounts are
 * deactivated and never deleted, an administrator never changes their own
 * role or status - not through the form, and not by editing the request the
 * form sends - and nobody but the employee chooses the employee's password.
 */
final class EmployeeResourceTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('admin');

        $this->admin = $this->makeAdmin();
        $this->actingAs($this->admin);
    }

    #[Test]
    public function the_list_create_and_edit_pages_render(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(ListEmployees::class)->assertOk();
        Livewire::test(CreateEmployee::class)->assertOk();
        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])->assertOk();
    }

    #[Test]
    public function the_create_form_asks_only_who_the_employee_is_and_what_they_may_do(): void
    {
        // No password, and no status either: a new account is pending until
        // its owner sets one, which is not something to choose here.
        Livewire::test(CreateEmployee::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('role')
            ->assertFormFieldHidden('status')
            ->assertFormFieldDoesNotExist('password')
            ->assertFormFieldDoesNotExist('password_confirmation');
    }

    #[Test]
    public function no_password_field_exists_on_the_edit_form_either(): void
    {
        // A password changes only through the reset action, which asks for
        // its own confirmation instead of riding along with a rename.
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldDoesNotExist('password');
    }

    #[Test]
    public function creating_an_employee_makes_a_pending_account_with_no_password(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'sara@company.test')->first();

        $this->assertNotNull($created);
        $this->assertSame(UserRole::Employee, $created->role);
        $this->assertSame(UserStatus::Pending, $created->status);
        $this->assertNull($created->password);
    }

    #[Test]
    public function an_ordinary_administrator_cannot_mint_an_administrator_on_the_create_form(): void
    {
        // Appointing an administrator belongs to the super administrator.
        // A rule that stopped at the edit form would be one Create button
        // wide, so the select is disabled and the submitted value dropped.
        Livewire::test(CreateEmployee::class)
            ->assertFormFieldDisabled('role')
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
                'role' => UserRole::Admin->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'sara@company.test')->first();

        $this->assertNotNull($created);
        $this->assertSame(UserRole::Employee, $created->role);
    }

    #[Test]
    public function the_super_administrator_can_appoint_an_administrator_on_the_create_form(): void
    {
        $this->actAsSuperAdmin();

        Livewire::test(CreateEmployee::class)
            ->assertFormFieldEnabled('role')
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
                'role' => UserRole::Admin->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(UserRole::Admin, User::query()->where('email', 'sara@company.test')->first()?->role);
    }

    #[Test]
    public function creating_an_employee_reports_that_the_invitation_could_not_be_emailed(): void
    {
        // The suite runs on the array mailer, exactly as the live
        // deployment runs on the log mailer: nothing is delivered, so the
        // administrator is pointed at the link instead.
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified(__('employees.notifications.invitation_not_emailed'));
    }

    #[Test]
    public function the_email_must_be_unique(): void
    {
        $this->makeEmployee('taken@company.test');

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Another Person',
                'email' => 'taken@company.test',
            ])
            ->call('create')
            ->assertHasFormErrors(['email' => 'unique']);
    }

    #[Test]
    public function role_and_status_are_locked_when_an_administrator_edits_their_own_account(): void
    {
        Livewire::test(EditEmployee::class, ['record' => $this->admin->getRouteKey()])
            ->assertFormFieldDisabled('role')
            ->assertFormFieldDisabled('status')
            ->assertSee(__('employees.helpers.own_access'));

        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldEnabled('status')
            ->assertDontSee(__('employees.helpers.own_access'));
    }

    #[Test]
    public function the_role_select_is_locked_for_an_ordinary_administrator_and_open_to_the_super_administrator(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldDisabled('role')
            ->assertSee(__('employees.helpers.role_super_admin_only'));

        $this->actAsSuperAdmin();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldEnabled('role')
            ->assertDontSee(__('employees.helpers.role_super_admin_only'));
    }

    #[Test]
    public function a_role_or_status_change_submitted_for_yourself_is_ignored(): void
    {
        // A disabled input is a browser courtesy; the request can still
        // carry the fields. The save must drop them.
        Livewire::test(EditEmployee::class, ['record' => $this->admin->getRouteKey()])
            ->fillForm([
                'name' => 'Renamed Administrator',
                'role' => UserRole::Employee->value,
                'status' => UserStatus::Inactive->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $admin = $this->admin->fresh();

        $this->assertSame('Renamed Administrator', $admin->name);
        $this->assertSame(UserRole::Admin, $admin->role);
        $this->assertSame(UserStatus::Active, $admin->status);
    }

    #[Test]
    public function an_ordinary_administrator_changes_another_accounts_status_but_not_its_role(): void
    {
        // A submitted role is dropped the way a submitted status is on the
        // administrator's own account: the disabled select is a courtesy,
        // the strip in mutateFormDataBeforeSave is the rule.
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm([
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Inactive->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee = $employee->fresh();

        $this->assertSame(UserRole::Employee, $employee->role);
        $this->assertSame(UserStatus::Inactive, $employee->status);
    }

    #[Test]
    public function the_super_administrator_promotes_and_demotes_another_account(): void
    {
        $employee = $this->makeEmployee();
        $this->actAsSuperAdmin();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['role' => UserRole::Admin->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UserRole::Admin, $employee->fresh()->role);

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm(['role' => UserRole::Employee->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UserRole::Employee, $employee->fresh()->role);
    }

    #[Test]
    public function a_waiting_accounts_status_is_locked_and_a_submitted_change_is_ignored(): void
    {
        // Otherwise an administrator could mark the account active while it
        // still has no password: it would look usable and nobody could ever
        // sign in to it.
        $employee = $this->pendingEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldDisabled('status')
            ->assertSee(__('employees.helpers.pending_status'))
            ->fillForm(['status' => UserStatus::Active->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UserStatus::Pending, $employee->fresh()->status);
    }

    #[Test]
    public function the_invitation_actions_are_offered_only_while_an_account_is_waiting(): void
    {
        $pending = $this->pendingEmployee();
        $active = $this->makeEmployee('active@company.test');
        $inactive = $this->makeEmployee('inactive@company.test', UserStatus::Inactive);

        Livewire::test(ListEmployees::class)
            ->assertTableActionVisible('copyInvitationLink', $pending)
            ->assertTableActionVisible('resendInvitation', $pending)
            ->assertTableActionHidden('copyInvitationLink', $active)
            ->assertTableActionHidden('resendInvitation', $active)
            ->assertTableActionHidden('copyInvitationLink', $inactive)
            ->assertTableActionHidden('resendInvitation', $inactive);
    }

    #[Test]
    public function the_reset_password_action_is_hidden_while_an_account_is_waiting(): void
    {
        // It has no password to replace, and giving it one here would leave
        // it pending and unable to sign in with it.
        $pending = $this->pendingEmployee();

        Livewire::test(ListEmployees::class)
            ->assertTableActionHidden('resetPassword', $pending);

        Livewire::test(EditEmployee::class, ['record' => $pending->getRouteKey()])
            ->assertActionHidden('resetPassword');
    }

    #[Test]
    public function the_copy_link_action_shows_a_link_the_administrator_can_pass_on(): void
    {
        $pending = $this->pendingEmployee();

        $link = Livewire::test(ListEmployees::class)
            ->mountTableAction('copyInvitationLink', $pending)
            ->get('mountedActions.0.data.invitation_link');

        $this->assertIsString($link);
        // Absolute so it survives being pasted into a message. The scheme
        // belongs to the deployment, so only the host is required here.
        $this->assertNotFalse(filter_var($link, FILTER_VALIDATE_URL), "Not an absolute URL: {$link}");
        $this->assertNotEmpty(parse_url($link, PHP_URL_HOST), "No host in: {$link}");
        $this->assertStringContainsString('/password-reset/reset', $link);
        $this->assertStringContainsString(urlencode($pending->email), $link);
    }

    #[Test]
    public function the_copy_link_action_is_offered_from_the_edit_page_too(): void
    {
        $pending = $this->pendingEmployee();

        Livewire::test(EditEmployee::class, ['record' => $pending->getRouteKey()])
            ->assertActionVisible('copyInvitationLink')
            ->assertActionVisible('resendInvitation');
    }

    #[Test]
    public function resending_the_invitation_reports_what_happened_to_the_email(): void
    {
        $pending = $this->pendingEmployee();

        Livewire::test(ListEmployees::class)
            ->callTableAction('resendInvitation', $pending)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.invitation_not_emailed'));

        $this->assertSame(UserStatus::Pending, $pending->fresh()->status);
    }

    #[Test]
    public function the_reset_password_action_changes_the_password_from_the_list(): void
    {
        $employee = $this->makeEmployee(password: 'old-password-1');

        Livewire::test(ListEmployees::class)
            ->callTableAction('resetPassword', $employee, data: [
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.password_reset'));

        $password = $employee->fresh()->password;

        $this->assertTrue(Hash::check('new-password-1', $password));
        $this->assertFalse(Hash::check('old-password-1', $password));
    }

    #[Test]
    public function the_reset_password_action_changes_the_password_from_the_edit_page(): void
    {
        $employee = $this->makeEmployee(password: 'old-password-1');

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('resetPassword', data: [
                'password' => 'new-password-1',
                'password_confirmation' => 'new-password-1',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('employees.notifications.password_reset'));

        $this->assertTrue(Hash::check('new-password-1', $employee->fresh()->password));
    }

    #[Test]
    public function the_reset_password_action_requires_a_matching_confirmation(): void
    {
        $employee = $this->makeEmployee(password: 'old-password-1');

        Livewire::test(ListEmployees::class)
            ->callTableAction('resetPassword', $employee, data: [
                'password' => 'new-password-1',
                'password_confirmation' => 'something-else',
            ])
            ->assertHasTableActionErrors(['password' => 'confirmed']);

        $this->assertTrue(Hash::check('old-password-1', $employee->fresh()->password));
    }

    #[Test]
    public function the_reset_password_action_requires_at_least_eight_characters(): void
    {
        $employee = $this->makeEmployee(password: 'old-password-1');

        Livewire::test(ListEmployees::class)
            ->callTableAction('resetPassword', $employee, data: [
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertHasTableActionErrors(['password' => 'min']);

        $this->assertTrue(Hash::check('old-password-1', $employee->fresh()->password));
    }

    #[Test]
    public function the_toggle_status_action_deactivates_and_reactivates_another_employee(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(ListEmployees::class)
            ->callTableAction('toggleStatus', $employee)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.deactivated', ['name' => $employee->name]));

        $this->assertSame(UserStatus::Inactive, $employee->fresh()->status);

        Livewire::test(ListEmployees::class)
            ->callTableAction('toggleStatus', $employee)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.activated', ['name' => $employee->name]));

        $this->assertSame(UserStatus::Active, $employee->fresh()->status);
    }

    #[Test]
    public function a_withdrawn_invitation_goes_back_to_waiting_rather_than_active(): void
    {
        // Reactivating an account that never set a password would produce
        // one nobody can sign in to, with the invitation actions hidden.
        $employee = $this->pendingEmployee();

        Livewire::test(ListEmployees::class)
            ->callTableAction('toggleStatus', $employee)
            ->assertNotified(__('employees.notifications.deactivated', ['name' => $employee->name]));

        $this->assertSame(UserStatus::Inactive, $employee->fresh()->status);

        Livewire::test(ListEmployees::class)
            ->callTableAction('toggleStatus', $employee->fresh())
            ->assertNotified(__('employees.notifications.reopened', ['name' => $employee->name]));

        $this->assertSame(UserStatus::Pending, $employee->fresh()->status);
    }

    #[Test]
    public function the_toggle_status_action_on_the_edit_page_refreshes_the_form(): void
    {
        // Otherwise the form still says "active" and the next Save would
        // quietly reactivate the account that was just switched off.
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->callAction('toggleStatus')
            ->assertHasNoActionErrors()
            ->assertSchemaStateSet(['status' => UserStatus::Inactive->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(UserStatus::Inactive, $employee->fresh()->status);
    }

    #[Test]
    public function access_actions_are_hidden_on_the_administrators_own_account(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(ListEmployees::class)
            ->assertTableActionVisible('toggleStatus', $employee)
            ->assertTableActionVisible('resetPassword', $employee)
            ->assertTableActionHidden('toggleStatus', $this->admin)
            ->assertTableActionHidden('resetPassword', $this->admin);

        Livewire::test(EditEmployee::class, ['record' => $this->admin->getRouteKey()])
            ->assertActionHidden('toggleStatus')
            ->assertActionHidden('resetPassword');
    }

    #[Test]
    public function an_ordinary_administrator_is_offered_no_way_to_delete_an_account(): void
    {
        // Not on the row, not in the header, and never in bulk: removing a
        // colleague is the super administrator's alone, and it is done one
        // at a time with their name in the confirmation.
        $employee = $this->makeEmployee();

        $page = Livewire::test(ListEmployees::class)
            ->assertTableActionHidden('delete', $employee)
            ->instance();

        if (! $page instanceof ListEmployees) {
            self::fail('Livewire returned something other than the employee list.');
        }

        $this->assertSame([], $page->getTable()->getFlatBulkActions());

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertActionHidden('delete');
    }

    #[Test]
    public function an_ordinary_administrator_can_see_a_deleted_account_but_not_bring_it_back(): void
    {
        // Reading who was removed is fair; undoing it is the super
        // administrator's, the same as doing it.
        $employee = $this->makeEmployee();
        $employee->delete();

        Livewire::test(ListEmployees::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$employee])
            ->assertTableActionHidden('restore', $employee);
    }

    #[Test]
    public function the_super_administrator_deletes_an_account_and_it_leaves_the_list(): void
    {
        $employee = $this->makeEmployee();
        $this->actAsSuperAdmin();

        Livewire::test(ListEmployees::class)
            ->assertTableActionVisible('delete', $employee)
            ->callTableAction('delete', $employee)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.deleted', ['name' => $employee->name]));

        $this->assertSoftDeleted($employee);

        Livewire::test(ListEmployees::class)->assertCanNotSeeTableRecords([$employee]);
    }

    #[Test]
    public function a_deleted_account_is_found_and_restored_through_the_deleted_accounts_filter(): void
    {
        $employee = $this->makeEmployee();
        $this->actAsSuperAdmin();

        $employee->delete();

        Livewire::test(ListEmployees::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$employee])
            ->assertTableActionVisible('restore', $employee)
            ->callTableAction('restore', $employee)
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('employees.notifications.restored', ['name' => $employee->name]));

        $this->assertNotSoftDeleted($employee);

        Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$employee]);
    }

    #[Test]
    public function a_deleted_account_offers_nothing_but_restore(): void
    {
        // There is no access to manage on an account that cannot sign in at
        // all; inviting it or switching it on would be theatre.
        $employee = $this->makeEmployee();
        $this->actAsSuperAdmin();

        $employee->delete();

        Livewire::test(ListEmployees::class)
            ->filterTable('trashed', false)
            ->assertTableActionVisible('restore', $employee)
            ->assertTableActionHidden('toggleStatus', $employee)
            ->assertTableActionHidden('resetPassword', $employee)
            ->assertTableActionHidden('delete', $employee);
    }

    #[Test]
    public function the_super_administrators_own_row_is_marked_protected_and_offers_no_destructive_action(): void
    {
        $superAdmin = $this->actAsSuperAdmin();

        Livewire::test(ListEmployees::class)
            ->assertSee(__('employees.super_admin.badge'))
            ->assertTableActionHidden('delete', $superAdmin)
            ->assertTableActionHidden('toggleStatus', $superAdmin)
            ->assertTableActionHidden('resetPassword', $superAdmin);

        Livewire::test(EditEmployee::class, ['record' => $superAdmin->getRouteKey()])
            ->assertActionHidden('delete')
            ->assertActionHidden('toggleStatus')
            ->assertActionHidden('resetPassword')
            ->assertFormFieldDisabled('role')
            ->assertFormFieldDisabled('status')
            ->assertSee(__('employees.super_admin.protected'));
    }

    #[Test]
    public function another_administrator_cannot_touch_the_super_administrator(): void
    {
        // The ordinary administrator from setUp() is still signed in; the
        // super administrator is somebody else's account entirely.
        $superAdmin = $this->makeAdmin('owner@company.test');
        config(['admin.super_admin_email' => 'owner@company.test']);

        Livewire::test(ListEmployees::class)
            ->assertTableActionHidden('delete', $superAdmin)
            ->assertTableActionHidden('toggleStatus', $superAdmin)
            ->assertTableActionHidden('resetPassword', $superAdmin);

        Livewire::test(EditEmployee::class, ['record' => $superAdmin->getRouteKey()])
            ->fillForm([
                'role' => UserRole::Employee->value,
                'status' => UserStatus::Inactive->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $superAdmin = $superAdmin->fresh();

        $this->assertSame(UserRole::Admin, $superAdmin->role);
        $this->assertSame(UserStatus::Active, $superAdmin->status);
    }

    #[Test]
    public function an_employee_is_refused_the_admin_panel_over_http(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($employee)->get('/admin/employees')->assertForbidden();
        $this->actingAs($employee)->get('/admin')->assertForbidden();
    }

    #[Test]
    public function an_administrator_reaches_the_employee_list_over_http(): void
    {
        $this->get('/admin/employees')->assertOk();
    }

    private function pendingEmployee(string $email = 'waiting@company.test'): User
    {
        return User::factory()->create([
            'email' => $email,
            'status' => UserStatus::Pending,
            'password' => null,
        ]);
    }

    /**
     * Turns the signed-in administrator into the super administrator by
     * pinning their address in the configuration, which is where the
     * designation lives - there is no column to set.
     */
    private function actAsSuperAdmin(): User
    {
        config(['admin.super_admin_email' => $this->admin->email]);

        return $this->admin;
    }
}
