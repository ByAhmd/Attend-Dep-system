<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Models\Attendance;
use App\Models\AttendanceRejection;
use App\Models\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The invariants the database enforces on its own, whatever writes to it.
 *
 * These run against MySQL on purpose: the unique index on the generated
 * column, the CHECK constraints and the foreign-key actions are the
 * behaviour under test, and SQLite would leave most of them unexercised.
 */
final class AttendanceSchemaTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeRiyadhClock('2026-09-02 10:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function one_employee_has_at_most_one_open_session_per_day(): void
    {
        // The generated column open_attendance_date carries the date only
        // while the session is open, so the unique index on
        // (user_id, open_attendance_date) forbids exactly this and nothing
        // else - whatever writes the row, workflow or not.
        $employee = $this->makeEmployee();
        $this->checkedIn($employee);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->checkedIn($employee);
    }

    #[Test]
    public function any_number_of_closed_sessions_fit_in_one_day(): void
    {
        $employee = $this->makeEmployee();

        $this->attendanceSession($employee, '08:00', '10:00');
        $this->attendanceSession($employee, '10:30', '12:30');
        $this->attendanceSession($employee, '13:00', '17:00');

        // ... and one still running on top of them.
        $this->attendanceSession($employee, '17:30');

        $this->assertDatabaseCount('attendances', 4);
        $this->assertSame(1, Attendance::query()->open()->count());
    }

    #[Test]
    public function a_session_left_open_on_an_earlier_day_does_not_block_todays(): void
    {
        $employee = $this->makeEmployee();

        $this->checkedIn($employee, Carbon::now()->subDay());
        $this->checkedIn($employee);

        // Two open rows for one employee, and the index allows them: they
        // carry different dates, so they are different days.
        $this->assertSame(2, Attendance::query()->open()->count());
    }

    #[Test]
    public function the_same_day_is_free_for_another_employee_and_another_day_for_the_same_one(): void
    {
        $employee = $this->makeEmployee();
        $colleague = $this->makeEmployee();

        $this->checkedIn($employee);
        $this->checkedIn($colleague);
        $this->checkedIn($employee, Carbon::now()->subDay());

        $this->assertDatabaseCount('attendances', 3);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unknownAccountValues(): array
    {
        return [
            'unknown role' => ['role', 'boss'],
            'unknown status' => ['status', 'suspended'],
        ];
    }

    #[Test]
    #[DataProvider('unknownAccountValues')]
    public function the_users_table_refuses_a_value_the_enums_do_not_know(string $column, string $value): void
    {
        $this->expectException(QueryException::class);

        DB::table('users')->insert([
            'name' => 'Nobody',
            'email' => 'nobody@attendance.test',
            'password' => 'not-a-real-hash',
            'role' => 'employee',
            'status' => 'active',
            $column => $value,
        ]);
    }

    #[Test]
    public function the_settings_table_holds_exactly_one_row(): void
    {
        AttendanceSetting::current();

        $this->expectException(QueryException::class);

        DB::table('attendance_settings')->insert(['id' => 2, 'radius_meters' => 150]);
    }

    #[Test]
    public function a_check_out_is_recorded_whole_or_not_at_all(): void
    {
        $attendance = $this->checkedIn($this->makeEmployee());

        try {
            DB::table('attendances')
                ->where('id', $attendance->id)
                ->update(['check_out_at' => '2026-09-02 17:00:00', 'check_out_accuracy' => 9.5]);

            $this->fail('A partial check-out was accepted by the attendances table.');
        } catch (QueryException) {
            // attendances_check_out_recorded_or_corrected refused the
            // half-written row.
        }

        $stored = $attendance->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertNull($stored->check_out_at);
        $this->assertNull($stored->check_out_accuracy);
        $this->assertTrue($stored->isOpen());
    }

    #[Test]
    public function an_account_with_attendance_history_cannot_be_deleted(): void
    {
        $employee = $this->makeEmployee();
        $this->checkedOut($employee);

        try {
            DB::table('users')->where('id', $employee->id)->delete();

            $this->fail('A user with attendance history was deleted.');
        } catch (QueryException) {
            // user_id on attendances restricts the delete.
        }

        $this->assertDatabaseHas('users', ['id' => $employee->id]);
        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function an_account_with_only_rejected_attempts_takes_them_with_it(): void
    {
        $employee = $this->makeEmployee();

        AttendanceRejection::query()->create([
            'user_id' => $employee->id,
            'action' => AttendanceAction::CheckIn,
            'latitude' => 24.7336,
            'longitude' => 46.6753,
            'accuracy' => 15.0,
            'distance_from_company' => 2223.9,
            'reason' => AttendanceRejectionReason::OutsideAllowedArea,
        ]);

        $this->assertDatabaseCount('attendance_rejections', 1);

        $deleted = DB::table('users')->where('id', $employee->id)->delete();

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('users', ['id' => $employee->id]);
        $this->assertDatabaseCount('attendance_rejections', 0);
        $this->assertNull(User::query()->find($employee->id));
    }
}
