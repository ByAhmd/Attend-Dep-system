<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\AttendanceCorrection;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * What the two request tables - and the setting that rations one of them -
 * refuse on their own, whatever writes to them.
 *
 * The services enforce these rules too, and say something readable when
 * they do. These tests are about the layer underneath: a row written by a
 * console command, a migration, a future workflow or a hand at a MySQL
 * prompt is refused just the same, so a request can never reach the
 * approval queue in a shape nothing can interpret.
 *
 * They run against MySQL on purpose. The unique indexes ride generated
 * columns and every rule below is a CHECK constraint; SQLite would leave
 * all of it unexercised.
 */
final class RequestSchemaTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-08 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * A correction row as the workflow would write it, so a test can change
     * exactly one thing and see the table refuse that one thing.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function correctionRow(int $userId, array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $userId,
            'attendance_id' => null,
            'attendance_date' => '2026-09-07',
            'reason' => 'forgot_to_record',
            'requested_check_in_time' => '08:00:00',
            'requested_check_out_time' => '17:00:00',
            'status' => 'pending',
            'submitted_at' => '2026-09-08 10:00:00',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function leaveRow(int $userId, array $overrides = []): array
    {
        return $overrides + [
            'user_id' => $userId,
            'type' => 'annual',
            'starts_on' => '2026-09-14',
            'ends_on' => '2026-09-18',
            'is_exit_and_return' => false,
            'reason' => 'سفر عائلي.',
            'status' => 'pending',
            'submitted_at' => '2026-09-08 10:00:00',
        ];
    }

    #[Test]
    public function one_employee_has_at_most_one_pending_correction_per_day(): void
    {
        $employee = $this->makeEmployee();

        $this->correctionRequest($employee, on: Carbon::parse('2026-09-07'));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->correctionRequest($employee, on: Carbon::parse('2026-09-07'));
    }

    #[Test]
    public function a_decided_request_frees_the_day_so_the_employee_may_ask_again(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();

        $first = $this->correctionRequest($employee, on: Carbon::parse('2026-09-07'));

        $first->forceFill([
            'status' => 'approved',
            'decided_by_id' => $admin->id,
            'decided_at' => Carbon::now(),
        ])->save();

        // The generated column carries the date only while the request is
        // pending, so a decided one drops out of the unique index.
        $second = $this->correctionRequest($employee, on: Carbon::parse('2026-09-07'));

        $this->assertDatabaseCount('attendance_corrections', 2);
        $this->assertNotSame($first->id, $second->id);
    }

    #[Test]
    public function the_same_day_is_free_for_another_employee(): void
    {
        $this->correctionRequest($this->makeEmployee(), on: Carbon::parse('2026-09-07'));
        $this->correctionRequest($this->makeEmployee(), on: Carbon::parse('2026-09-07'));

        $this->assertDatabaseCount('attendance_corrections', 2);
    }

    #[Test]
    public function a_correction_that_asks_for_neither_time_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'requested_check_in_time' => null,
            'requested_check_out_time' => null,
        ]));
    }

    #[Test]
    public function a_check_out_time_before_the_check_in_time_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'requested_check_in_time' => '17:00:00',
            'requested_check_out_time' => '08:00:00',
        ]));
    }

    #[Test]
    public function a_reason_the_enum_does_not_know_is_refused(): void
    {
        $employee = $this->makeEmployee();

        // There is no fingerprint device in this system, so there is no
        // fingerprint reason - in the enum or in the table.
        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'reason' => 'fingerprint_device_problem',
        ]));
    }

    #[Test]
    public function a_request_status_the_enum_does_not_know_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();

        // Decided completely, so the only rule left to break is the list of
        // statuses itself. There is no "withdrawn" in this product.
        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'status' => 'withdrawn',
            'decided_by_id' => $admin->id,
            'decided_at' => '2026-09-08 11:00:00',
        ]));
    }

    #[Test]
    public function a_decided_correction_with_nobody_who_decided_it_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'status' => 'approved',
            'decided_at' => '2026-09-08 11:00:00',
        ]));
    }

    #[Test]
    public function a_pending_correction_carrying_a_decision_is_refused(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('attendance_corrections')->insert($this->correctionRow($employee->id, [
            'decided_by_id' => $admin->id,
            'decided_at' => '2026-09-08 11:00:00',
        ]));
    }

    #[Test]
    public function an_account_with_a_request_cannot_be_deleted_outright(): void
    {
        $employee = $this->makeEmployee();
        $this->correctionRequest($employee);

        try {
            DB::table('users')->where('id', $employee->id)->delete();

            $this->fail('An account with a correction request was deleted.');
        } catch (QueryException) {
            // user_id on attendance_corrections restricts the delete.
        }

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
        $this->assertDatabaseCount('attendance_corrections', 1);
    }

    #[Test]
    public function a_soft_deleted_account_keeps_its_requests_and_takes_them_out_of_the_queue(): void
    {
        $employee = $this->makeEmployee();
        $correction = $this->correctionRequest($employee);
        $leave = $this->leaveRequest($employee);

        $employee->delete();

        // The rows are untouched - deleting an account hides it and keeps
        // its records - but nobody can be asked to decide a request from an
        // account that no longer exists.
        $this->assertDatabaseHas('attendance_corrections', ['id' => $correction->id, 'status' => 'pending']);
        $this->assertSame(0, AttendanceCorrection::query()->actionable()->count());
        $this->assertSame(0, LeaveRequest::query()->actionable()->count());

        // Restoring the account brings them back, because the queue reads
        // live state instead of a flag stamped at the moment of deletion.
        $employee->restore();

        $this->assertSame(1, AttendanceCorrection::query()->actionable()->count());
        $this->assertSame(1, LeaveRequest::query()->actionable()->count());
        $this->assertSame($leave->id, LeaveRequest::query()->actionable()->sole()->id);
    }

    #[Test]
    public function a_leave_request_that_ends_before_it_starts_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('leave_requests')->insert($this->leaveRow($employee->id, [
            'starts_on' => '2026-09-18',
            'ends_on' => '2026-09-14',
        ]));
    }

    #[Test]
    public function a_leave_request_with_an_empty_reason_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('leave_requests')->insert($this->leaveRow($employee->id, ['reason' => '']));
    }

    #[Test]
    public function a_leave_type_the_enum_does_not_know_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('leave_requests')->insert($this->leaveRow($employee->id, ['type' => 'sabbatical']));
    }

    #[Test]
    public function a_decided_leave_request_with_nobody_who_decided_it_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('leave_requests')->insert($this->leaveRow($employee->id, [
            'status' => 'rejected',
            'decided_at' => '2026-09-08 11:00:00',
        ]));
    }

    #[Test]
    public function one_employee_has_at_most_one_pending_leave_request_starting_on_a_day(): void
    {
        $employee = $this->makeEmployee();

        $this->leaveRequest($employee, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-18'));

        // The double tap, not the deliberate clash: overlap is a different
        // question, and no engine here can express it as an index.
        $this->expectException(UniqueConstraintViolationException::class);

        $this->leaveRequest($employee, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-15'));
    }

    #[Test]
    public function a_decided_leave_request_frees_its_start_date(): void
    {
        $admin = $this->makeAdmin();
        $employee = $this->makeEmployee();

        $first = $this->leaveRequest($employee, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-18'));

        $first->forceFill([
            'status' => 'rejected',
            'decided_by_id' => $admin->id,
            'decided_at' => Carbon::now(),
            'decision_note' => 'الفريق ناقص في هذا الأسبوع.',
        ])->save();

        $this->leaveRequest($employee, Carbon::parse('2026-09-14'), Carbon::parse('2026-09-15'));

        $this->assertDatabaseCount('leave_requests', 2);
    }

    #[Test]
    public function an_attachment_is_stored_whole_or_not_at_all(): void
    {
        $employee = $this->makeEmployee();

        try {
            DB::table('leave_requests')->insert($this->leaveRow($employee->id, [
                'attachment_path' => 'leave/2026/note.pdf',
            ]));

            $this->fail('A half-written attachment was accepted by the leave_requests table.');
        } catch (QueryException) {
            // leave_requests_attachment_complete_check refused a path with
            // no name, size or type to serve it by.
        }

        DB::table('leave_requests')->insert($this->leaveRow($employee->id, [
            'attachment_path' => 'leave/2026/note.pdf',
            'attachment_name' => 'تقرير طبي.pdf',
            'attachment_size' => 20480,
            'attachment_mime_type' => 'application/pdf',
        ]));

        $this->assertDatabaseCount('leave_requests', 1);
    }

    #[Test]
    public function the_monthly_correction_allowance_stays_inside_a_month(): void
    {
        // Zero is a real answer - it switches correction requests off - and
        // the ceiling is one request for every day of the longest month.
        // Anything past that is an unlimited allowance wearing a number, and
        // this setting is edited from a form, so the table holds the range.
        $this->configureCorrectionQuota(0);
        $this->configureCorrectionQuota(31);

        $this->expectException(QueryException::class);

        $this->configureCorrectionQuota(32);
    }
}
