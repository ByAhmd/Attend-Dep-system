<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\JobTitle;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * Who may read, file and decide a request, as the Gate resolves it.
 *
 * The rule worth the most assertions is the one an owner will ask to have
 * relaxed: nobody decides their own request, administrators included. An
 * administrator is staff, checks in like everybody else, and an
 * administrator who could approve their own correction would be typing
 * their own check-in time into the record that exists to say when they
 * arrived.
 *
 * The two request policies are asserted side by side and verb for verb,
 * because they are deliberately identical and the value of that is lost the
 * first time one of them quietly drifts.
 */
final class RequestPolicyTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string OWNER_EMAIL = 'owner@company.test';

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
    public function an_employee_reads_only_their_own_requests(): void
    {
        $own = $this->correctionRequest($this->employee);
        $theirs = $this->correctionRequest($this->colleague);
        $ownLeave = $this->leaveRequest($this->employee);
        $theirLeave = $this->leaveRequest($this->colleague);

        $gate = Gate::forUser($this->employee);

        $this->assertTrue($gate->allows('view', $own));
        $this->assertFalse($gate->allows('view', $theirs));
        $this->assertTrue($gate->allows('view', $ownLeave));
        $this->assertFalse($gate->allows('view', $theirLeave));

        // There is no list of everybody's requests for an employee to open.
        $this->assertFalse($gate->allows('viewAny', AttendanceCorrection::class));
        $this->assertFalse($gate->allows('viewAny', LeaveRequest::class));
    }

    #[Test]
    public function an_administrator_reads_every_request(): void
    {
        $correction = $this->correctionRequest($this->employee);
        $leave = $this->leaveRequest($this->employee);

        $gate = Gate::forUser($this->admin);

        $this->assertTrue($gate->allows('viewAny', AttendanceCorrection::class));
        $this->assertTrue($gate->allows('viewAny', LeaveRequest::class));
        $this->assertTrue($gate->allows('view', $correction));
        $this->assertTrue($gate->allows('view', $leave));
    }

    #[Test]
    public function filing_a_request_needs_an_account_that_is_still_active(): void
    {
        $suspended = $this->makeEmployee(status: UserStatus::Inactive);

        $this->assertTrue(Gate::forUser($this->employee)->allows('create', AttendanceCorrection::class));
        $this->assertTrue(Gate::forUser($this->employee)->allows('create', LeaveRequest::class));
        $this->assertFalse(Gate::forUser($suspended)->allows('create', AttendanceCorrection::class));
        $this->assertFalse(Gate::forUser($suspended)->allows('create', LeaveRequest::class));
    }

    #[Test]
    public function an_administrator_decides_a_pending_request_from_somebody_else(): void
    {
        $correction = $this->correctionRequest($this->employee);
        $leave = $this->leaveRequest($this->employee);

        $this->assertTrue(Gate::forUser($this->admin)->allows('decide', $correction));
        $this->assertTrue(Gate::forUser($this->admin)->allows('decide', $leave));
    }

    #[Test]
    public function nobody_decides_their_own_request_administrators_included(): void
    {
        $ownCorrection = $this->correctionRequest($this->admin);
        $ownLeave = $this->leaveRequest($this->admin);

        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $ownCorrection));
        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $ownLeave));

        // Another administrator may, which is the remedy the screen names:
        // appoint a second one.
        $second = $this->makeAdmin();

        $this->assertTrue(Gate::forUser($second)->allows('decide', $ownCorrection));
        $this->assertTrue(Gate::forUser($second)->allows('decide', $ownLeave));
    }

    #[Test]
    public function the_super_administrator_does_not_decide_their_own_request_either(): void
    {
        $owner = $this->owner();

        $ownCorrection = $this->correctionRequest($owner);
        $ownLeave = $this->leaveRequest($owner);

        $this->assertTrue($owner->isSuperAdmin());
        $this->assertFalse(Gate::forUser($owner)->allows('decide', $ownCorrection));
        $this->assertFalse(Gate::forUser($owner)->allows('decide', $ownLeave));
    }

    #[Test]
    public function a_decided_request_is_never_decided_again(): void
    {
        $correction = AttendanceCorrection::factory()->for($this->employee)->approvedBy($this->admin)->create();
        $leave = LeaveRequest::factory()->for($this->employee)->rejectedBy($this->admin, 'لا يمكن.')->create();

        $second = $this->makeAdmin();

        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $correction));
        $this->assertFalse(Gate::forUser($second)->allows('decide', $correction));
        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $leave));
        $this->assertFalse(Gate::forUser($second)->allows('decide', $leave));
    }

    #[Test]
    public function a_request_from_a_deleted_account_leaves_the_queue_and_comes_back_when_the_account_does(): void
    {
        $correction = $this->correctionRequest($this->employee);
        $leave = $this->leaveRequest($this->employee);

        $this->employee->delete();

        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $correction->fresh()));
        $this->assertFalse(Gate::forUser($this->admin)->allows('decide', $leave->fresh()));

        $this->employee->restore();

        $this->assertTrue(Gate::forUser($this->admin)->allows('decide', $correction->fresh()));
        $this->assertTrue(Gate::forUser($this->admin)->allows('decide', $leave->fresh()));
    }

    #[Test]
    public function an_employee_never_decides_anything(): void
    {
        $theirs = $this->correctionRequest($this->colleague);
        $own = $this->correctionRequest($this->employee);
        $ownLeave = $this->leaveRequest($this->employee);

        $gate = Gate::forUser($this->employee);

        $this->assertFalse($gate->allows('decide', $theirs));
        $this->assertFalse($gate->allows('decide', $own));
        $this->assertFalse($gate->allows('decide', $ownLeave));
    }

    #[Test]
    public function no_request_is_ever_edited_or_deleted_by_anybody(): void
    {
        $owner = $this->owner();
        $correction = $this->correctionRequest($this->employee);
        $leave = $this->leaveRequest($this->employee);

        foreach ([$owner, $this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('update', $correction));
            $this->assertFalse($gate->allows('delete', $correction));
            $this->assertFalse($gate->allows('deleteAny', AttendanceCorrection::class));
            $this->assertFalse($gate->allows('update', $leave));
            $this->assertFalse($gate->allows('delete', $leave));
            $this->assertFalse($gate->allows('deleteAny', LeaveRequest::class));
        }
    }

    #[Test]
    public function attendance_is_still_written_through_the_gate_by_nobody_now_that_corrections_exist(): void
    {
        $record = $this->checkedIn($this->employee);
        $owner = $this->owner();

        foreach ([$owner, $this->admin, $this->employee] as $user) {
            $gate = Gate::forUser($user);

            $this->assertFalse($gate->allows('create', Attendance::class));
            $this->assertFalse($gate->allows('update', $record));
            $this->assertFalse($gate->allows('delete', $record));
            $this->assertFalse($gate->allows('deleteAny', Attendance::class));
        }
    }

    #[Test]
    public function job_titles_are_kept_by_administrators_and_read_by_nobody_else(): void
    {
        $title = $this->makeJobTitle();

        $admin = Gate::forUser($this->admin);

        $this->assertTrue($admin->allows('viewAny', JobTitle::class));
        $this->assertTrue($admin->allows('view', $title));
        $this->assertTrue($admin->allows('create', JobTitle::class));
        $this->assertTrue($admin->allows('update', $title));
        $this->assertTrue($admin->allows('delete', $title));
        $this->assertFalse($admin->allows('deleteAny', JobTitle::class));

        $employee = Gate::forUser($this->employee);

        $this->assertFalse($employee->allows('viewAny', JobTitle::class));
        $this->assertFalse($employee->allows('view', $title));
        $this->assertFalse($employee->allows('create', JobTitle::class));
        $this->assertFalse($employee->allows('update', $title));
        $this->assertFalse($employee->allows('delete', $title));
    }

    /**
     * The account designated in the environment, which outranks every
     * column in the database - and is still refused its own request.
     */
    private function owner(): User
    {
        config(['admin.super_admin_email' => self::OWNER_EMAIL]);

        return User::factory()->create([
            'name' => 'The Owner',
            'email' => self::OWNER_EMAIL,
            'password' => 'secret',
            'role' => UserRole::Admin,
            'status' => UserStatus::Active,
        ]);
    }
}
