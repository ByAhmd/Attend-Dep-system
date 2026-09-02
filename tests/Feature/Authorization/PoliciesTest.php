<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\AttendanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The policies as the Gate resolves them, one per model.
 *
 * Employees read their own attendance and nothing else; administrators read
 * everything and manage accounts and settings; nobody writes attendance or
 * deletes an account, administrators included.
 */
final class PoliciesTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private User $admin;

    private User $employee;

    private User $colleague;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->makeAdmin();
        $this->employee = $this->makeEmployee();
        $this->colleague = $this->makeEmployee();
    }

    #[Test]
    public function an_employee_reads_only_their_own_attendance(): void
    {
        $own = $this->checkedIn($this->employee);
        $theirs = $this->checkedIn($this->colleague);

        $this->assertTrue(Gate::forUser($this->employee)->allows('view', $own));
        $this->assertFalse(Gate::forUser($this->employee)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($this->employee)->allows('viewAny', Attendance::class));
    }

    #[Test]
    public function an_administrator_reads_every_attendance_record(): void
    {
        $record = $this->checkedIn($this->employee);

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', Attendance::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $record));
    }

    #[Test]
    public function nobody_writes_attendance_through_the_gate(): void
    {
        $record = $this->checkedIn($this->employee);

        foreach ([$this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('create', Attendance::class));
            $this->assertFalse($gate->allows('update', $record));
            $this->assertFalse($gate->allows('delete', $record));
            $this->assertFalse($gate->allows('deleteAny', Attendance::class));
        }
    }

    #[Test]
    public function the_settings_are_read_and_changed_by_administrators_only(): void
    {
        $settings = AttendanceSetting::current();

        $this->assertFalse(Gate::forUser($this->employee)->allows('viewAny', AttendanceSetting::class));
        $this->assertFalse(Gate::forUser($this->employee)->allows('view', $settings));
        $this->assertFalse(Gate::forUser($this->employee)->allows('update', $settings));

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', AttendanceSetting::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $settings));
        $this->assertTrue(Gate::forUser($this->admin)->allows('update', $settings));
    }

    #[Test]
    public function the_settings_row_is_never_created_or_deleted_from_the_interface(): void
    {
        $settings = AttendanceSetting::current();

        foreach ([$this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('create', AttendanceSetting::class));
            $this->assertFalse($gate->allows('delete', $settings));
            $this->assertFalse($gate->allows('deleteAny', AttendanceSetting::class));
        }
    }

    #[Test]
    public function an_administrator_manages_accounts_and_an_employee_does_not(): void
    {
        $admin = Gate::forUser($this->admin);
        $employee = Gate::forUser($this->employee);

        $this->assertTrue($admin->allows('viewAny', User::class));
        $this->assertTrue($admin->allows('view', $this->employee));
        $this->assertTrue($admin->allows('create', User::class));
        $this->assertTrue($admin->allows('update', $this->employee));

        $this->assertFalse($employee->allows('viewAny', User::class));
        $this->assertFalse($employee->allows('view', $this->colleague));
        $this->assertFalse($employee->allows('view', $this->employee));
        $this->assertFalse($employee->allows('create', User::class));
        $this->assertFalse($employee->allows('update', $this->colleague));
        $this->assertFalse($employee->allows('update', $this->employee));
    }

    #[Test]
    public function accounts_are_deactivated_never_deleted(): void
    {
        foreach ([$this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('delete', $this->colleague));
            $this->assertFalse($gate->allows('deleteAny', User::class));
            $this->assertFalse($gate->allows('forceDelete', $this->colleague));
            $this->assertFalse($gate->allows('restore', $this->colleague));
        }
    }

    #[Test]
    public function an_administrator_cannot_change_their_own_access(): void
    {
        $otherAdmin = $this->makeAdmin();

        $this->assertFalse(Gate::forUser($this->admin)->allows('manageAccess', $this->admin));
        $this->assertTrue(Gate::forUser($this->admin)->allows('manageAccess', $this->employee));
        $this->assertTrue(Gate::forUser($this->admin)->allows('manageAccess', $otherAdmin));
        $this->assertFalse(Gate::forUser($this->employee)->allows('manageAccess', $this->colleague));
        $this->assertFalse(Gate::forUser($this->employee)->allows('manageAccess', $this->employee));
    }

    #[Test]
    public function the_audit_trail_is_for_administrators_and_read_only(): void
    {
        $rejection = AttendanceRejection::query()->create([
            'user_id' => $this->employee->id,
            'action' => 'check_in',
            'latitude' => 24.7336,
            'longitude' => 46.6753,
            'accuracy' => 15.0,
            'distance_from_company' => 2223.9,
            'reason' => 'outside_allowed_area',
        ]);

        $this->assertTrue(Gate::forUser($this->admin)->allows('viewAny', AttendanceRejection::class));
        $this->assertTrue(Gate::forUser($this->admin)->allows('view', $rejection));
        $this->assertFalse(Gate::forUser($this->employee)->allows('viewAny', AttendanceRejection::class));
        $this->assertFalse(Gate::forUser($this->employee)->allows('view', $rejection));

        foreach ([$this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('create', AttendanceRejection::class));
            $this->assertFalse($gate->allows('update', $rejection));
            $this->assertFalse($gate->allows('delete', $rejection));
            $this->assertFalse($gate->allows('deleteAny', AttendanceRejection::class));
        }
    }
}
