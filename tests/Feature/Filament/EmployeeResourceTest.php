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
 * EmployeeResource: the one screen that creates accounts, changes what
 * they may do, and hands them a new password.
 *
 * The rules under test are the business rules of section 3: accounts are
 * deactivated and never deleted, and an administrator never changes their
 * own role or status - not through the form, and not by editing the
 * request the form sends.
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
    public function the_create_form_carries_the_account_fields_and_the_password(): void
    {
        Livewire::test(CreateEmployee::class)
            ->assertFormFieldExists('name')
            ->assertFormFieldExists('email')
            ->assertFormFieldExists('role')
            ->assertFormFieldExists('status')
            ->assertFormFieldExists('password')
            ->assertFormFieldExists('password_confirmation')
            ->assertFormFieldVisible('password');
    }

    #[Test]
    public function the_password_is_not_on_the_edit_form(): void
    {
        // Afterwards it changes only through the reset action, which asks
        // for its own confirmation instead of riding along with a rename.
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertFormFieldHidden('password');
    }

    #[Test]
    public function creating_an_employee_stores_a_hashed_password_with_the_chosen_role_and_status(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Inactive->value,
                'password' => 'first-password-1',
                'password_confirmation' => 'first-password-1',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = User::query()->where('email', 'sara@company.test')->first();

        $this->assertNotNull($created);
        $this->assertSame(UserRole::Admin, $created->role);
        $this->assertSame(UserStatus::Inactive, $created->status);
        $this->assertNotSame('first-password-1', $created->password);
        $this->assertTrue(Hash::check('first-password-1', $created->password));
    }

    #[Test]
    public function the_password_must_be_confirmed_and_at_least_eight_characters(): void
    {
        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Sara Ali',
                'email' => 'sara@company.test',
                'password' => 'short',
                'password_confirmation' => 'different',
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertNull(User::query()->where('email', 'sara@company.test')->first());
    }

    #[Test]
    public function the_email_must_be_unique(): void
    {
        $this->makeEmployee('taken@company.test');

        Livewire::test(CreateEmployee::class)
            ->fillForm([
                'name' => 'Another Person',
                'email' => 'taken@company.test',
                'password' => 'first-password-1',
                'password_confirmation' => 'first-password-1',
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
            ->assertFormFieldEnabled('role')
            ->assertFormFieldEnabled('status')
            ->assertDontSee(__('employees.helpers.own_access'));
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
    public function another_accounts_role_and_status_can_be_changed(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->fillForm([
                'role' => UserRole::Admin->value,
                'status' => UserStatus::Inactive->value,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $employee = $employee->fresh();

        $this->assertSame(UserRole::Admin, $employee->role);
        $this->assertSame(UserStatus::Inactive, $employee->status);
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
            ->assertHasTableActionErrors(['password']);

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
    public function no_delete_action_exists_anywhere(): void
    {
        $employee = $this->makeEmployee();

        Livewire::test(ListEmployees::class)
            ->assertTableActionDoesNotExist('delete')
            ->assertTableBulkActionDoesNotExist('delete');

        Livewire::test(EditEmployee::class, ['record' => $employee->getRouteKey()])
            ->assertActionDoesNotExist('delete');
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
}
