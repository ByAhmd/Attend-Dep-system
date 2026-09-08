<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Models\User;
use App\Support\Geo\Coordinates;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The rule that keeps a corrected attendance row honest.
 *
 * Until corrections existed, every recorded moment on this table carried a
 * position, because AttendanceWorkflow was the only writer and it only ever
 * wrote what a device reported. A correction fills in a moment the device
 * never saw, and the table now allows a moment with no coordinates in
 * exactly one place: where a correction id says which approved request
 * supplied it.
 *
 * So the guarantee has changed shape rather than weakened. "Every recorded
 * moment has coordinates" is gone; "a recorded moment WITHOUT coordinates
 * could only have come from an approved correction" has replaced it, and
 * that is the stronger of the two, because it is the one that makes a
 * corrected time impossible to pass off as a verified one.
 *
 * Everything below is enforced by the database, not by a service, so it
 * holds for a row written by a console command, a future workflow or a hand
 * at a MySQL prompt.
 */
final class CorrectedAttendanceSchemaTest extends TestCase
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
     * An approved request to point a corrected moment at.
     */
    private function approvedCorrection(User $employee): AttendanceCorrection
    {
        $correction = $this->correctionRequest($employee);

        $correction->forceFill([
            'status' => 'approved',
            'decided_by_id' => $this->makeAdmin()->id,
            'decided_at' => Carbon::now(),
        ])->save();

        return $correction;
    }

    #[Test]
    public function a_check_out_with_no_coordinates_and_no_correction_is_refused(): void
    {
        $session = $this->checkedIn($this->makeEmployee());

        try {
            DB::table('attendances')->where('id', $session->id)->update([
                'check_out_at' => '2026-09-08 17:00:00',
            ]);

            $this->fail('A check-out with no position and no correction was accepted.');
        } catch (QueryException) {
            // attendances_check_out_recorded_or_corrected refused it.
        }

        $stored = $session->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertTrue($stored->isOpen());
    }

    #[Test]
    public function a_check_out_with_no_coordinates_is_accepted_when_a_correction_supplied_it(): void
    {
        $employee = $this->makeEmployee();
        $session = $this->checkedIn($employee);
        $correction = $this->approvedCorrection($employee);

        DB::table('attendances')->where('id', $session->id)->update([
            'check_out_at' => '2026-09-08 17:00:00',
            'check_out_correction_id' => $correction->id,
        ]);

        $stored = $session->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertFalse($stored->isOpen());
        $this->assertTrue($stored->isCheckOutCorrected());
        $this->assertFalse($stored->hasDeviceCheckOut());

        // The device recorded nothing for that moment, and the row says so
        // rather than inventing a position for it.
        $this->assertNull($stored->deviceCheckOutAt());
        $this->assertNull($stored->check_out_distance_from_company);
    }

    #[Test]
    public function a_check_in_with_no_coordinates_and_no_correction_is_refused(): void
    {
        $employee = $this->makeEmployee();

        $this->expectException(QueryException::class);

        DB::table('attendances')->insert([
            'user_id' => $employee->id,
            'attendance_date' => '2026-09-07',
            'check_in_at' => '2026-09-07 08:00:00',
            'check_in_latitude' => null,
            'check_in_longitude' => null,
            'check_in_accuracy' => null,
            'check_in_distance_from_company' => null,
        ]);
    }

    #[Test]
    public function a_half_cleared_check_in_is_refused_even_with_a_correction(): void
    {
        $employee = $this->makeEmployee();
        $session = $this->checkedOut($employee);
        $correction = $this->approvedCorrection($employee);

        // Three of the four cleared is a row that reads as a device reading
        // and is not one.
        $this->expectException(QueryException::class);

        DB::table('attendances')->where('id', $session->id)->update([
            'check_in_correction_id' => $correction->id,
            'check_in_latitude' => null,
            'check_in_longitude' => null,
            'check_in_accuracy' => null,
        ]);
    }

    #[Test]
    public function a_session_created_wholly_by_a_correction_carries_no_reading_at_all(): void
    {
        $employee = $this->makeEmployee();
        $correction = $this->approvedCorrection($employee);

        DB::table('attendances')->insert([
            'user_id' => $employee->id,
            'attendance_date' => '2026-09-07',
            'check_in_at' => '2026-09-07 08:00:00',
            'check_out_at' => '2026-09-07 17:00:00',
            'check_in_correction_id' => $correction->id,
            'check_out_correction_id' => $correction->id,
        ]);

        $session = Attendance::query()->sole();

        $this->assertTrue($session->isCorrected());
        $this->assertFalse($session->hasDeviceCheckIn());
        $this->assertFalse($session->hasDeviceCheckOut());
        $this->assertNull($session->checkInCoordinates());
        $this->assertNull($session->checkOutCoordinates());
    }

    #[Test]
    public function an_archived_original_with_no_correction_to_explain_it_is_refused(): void
    {
        $session = $this->checkedOut($this->makeEmployee());

        $this->expectException(QueryException::class);

        DB::table('attendances')->where('id', $session->id)->update([
            'original_check_in_at' => '2026-09-08 08:02:00',
        ]);
    }

    #[Test]
    public function an_archived_original_is_kept_beside_the_corrected_moment(): void
    {
        $employee = $this->makeEmployee();
        $session = $this->attendanceSession($employee, '08:02', '17:04');
        $correction = $this->approvedCorrection($employee);

        $device = $session->check_in_at;

        DB::table('attendances')->where('id', $session->id)->update([
            'original_check_in_at' => $device->format('Y-m-d H:i:s'),
            'check_in_at' => '2026-09-08 07:30:00',
            'check_in_correction_id' => $correction->id,
        ]);

        $stored = $session->fresh();

        $this->assertInstanceOf(Attendance::class, $stored);
        $this->assertSame('07:30', $stored->check_in_at->format('H:i'));

        // The corrected moment is what the screens read; what the device
        // recorded is still there, and so is the position it recorded from.
        $this->assertSame($device->format('H:i'), $stored->deviceCheckInAt()?->format('H:i'));
        $this->assertTrue($stored->hasDeviceCheckIn());
        $this->assertInstanceOf(Coordinates::class, $stored->checkInCoordinates());
    }

    #[Test]
    public function the_correction_that_amended_a_session_cannot_be_deleted_while_it_stands(): void
    {
        $employee = $this->makeEmployee();
        $session = $this->checkedIn($employee);
        $correction = $this->approvedCorrection($employee);

        DB::table('attendances')->where('id', $session->id)->update([
            'check_out_at' => '2026-09-08 17:00:00',
            'check_out_correction_id' => $correction->id,
        ]);

        try {
            DB::table('attendance_corrections')->where('id', $correction->id)->delete();

            $this->fail('The correction that explains a corrected moment was deleted.');
        } catch (QueryException) {
            // check_out_correction_id restricts the delete, so a corrected
            // moment can never be left with nothing to explain it.
        }

        $this->assertDatabaseHas('attendance_corrections', ['id' => $correction->id]);
    }

    #[Test]
    public function filling_a_forgotten_check_out_frees_the_days_open_session_index(): void
    {
        $employee = $this->makeEmployee();
        $yesterday = Carbon::parse('2026-09-07');

        $forgotten = $this->checkedIn($employee, $yesterday);
        $correction = $this->approvedCorrection($employee);

        $this->assertSame(
            $yesterday->toDateString(),
            DB::table('attendances')->where('id', $forgotten->id)->value('open_attendance_date'),
        );

        DB::table('attendances')->where('id', $forgotten->id)->update([
            'check_out_at' => '2026-09-07 17:00:00',
            'check_out_correction_id' => $correction->id,
        ]);

        // The generated column empties itself, so the day is open again for
        // a session the employee genuinely started - which is the whole
        // reason the index rides a derived column rather than a stored one.
        $this->assertNull(DB::table('attendances')->where('id', $forgotten->id)->value('open_attendance_date'));

        $reopened = $this->checkedIn($employee, $yesterday);

        $this->assertDatabaseCount('attendances', 2);
        $this->assertNotSame($forgotten->id, $reopened->id);
    }
}
