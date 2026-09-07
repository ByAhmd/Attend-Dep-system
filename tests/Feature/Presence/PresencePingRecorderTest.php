<?php

declare(strict_types=1);

namespace Tests\Feature\Presence;

use App\Models\PresencePing;
use App\Models\User;
use App\Services\Attendance\PresencePingRecorder;
use App\Support\Geo\LocationReading;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * PresencePingRecorder: what is recorded while a session is open, and what
 * is not recorded when none is.
 *
 * Every distance asserted here is the one the server computed from the two
 * coordinates, and every timestamp is the frozen server clock, so a browser
 * that lied about either would fail these tests.
 */
final class PresencePingRecorderTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-02 10:20:00';

    #[Test]
    public function a_ping_during_an_open_session_stores_the_servers_distance_and_moment(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $session = $this->attendanceSession($employee, '08:00');
        $reading = $this->readingMetersFromCompany(80.0, accuracyMeters: 12.5);

        $ping = $this->record($employee, $reading);

        $this->assertInstanceOf(PresencePing::class, $ping);
        $this->assertSame($employee->id, $ping->user_id);
        $this->assertSame($session->id, $ping->attendance_id);
        $this->assertSame('80.00', $ping->distance_from_company);
        $this->assertSame('12.50', $ping->accuracy);
        $this->assertTrue($ping->is_inside);
        $this->assertTrue($now->equalTo($ping->created_at));
        $this->assertSame(
            round($reading->coordinates->latitude, 7),
            round((float) $ping->latitude, 7),
        );
    }

    #[Test]
    public function a_ping_with_no_session_open_records_nothing(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();

        $this->assertNull($this->record($employee, $this->readingAtCompany()));
        $this->assertDatabaseCount('presence_pings', 0);
    }

    #[Test]
    public function a_ping_after_the_employee_has_checked_out_records_nothing(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock('2026-09-02 13:00:00');

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00', '12:30');

        $this->assertNull($this->record($employee, $this->readingAtCompany()));
        $this->assertDatabaseCount('presence_pings', 0);
    }

    #[Test]
    public function a_ping_outside_the_radius_is_stored_and_flagged(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00');

        $ping = $this->record($employee, $this->readingMetersFromCompany(900.0));

        $this->assertInstanceOf(PresencePing::class, $ping);
        $this->assertSame('900.00', $ping->distance_from_company);
        $this->assertFalse($ping->is_inside);
    }

    #[Test]
    public function a_ping_at_exactly_the_radius_is_inside_it(): void
    {
        $this->configureCompanyLocation(radiusMeters: 150);
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00');

        $this->assertTrue($this->record($employee, $this->readingMetersFromCompany(150.0))?->is_inside);
        $this->assertFalse($this->record($employee, $this->readingMetersFromCompany(150.5))?->is_inside);
    }

    #[Test]
    public function a_loose_fix_is_recorded_with_its_accuracy_rather_than_discarded(): void
    {
        // A ping decides nothing, so a poor reading is kept and shown with
        // the accuracy that qualifies it. Dropping it would leave a gap
        // that reads like an absence.
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00');

        $ping = $this->record($employee, $this->readingMetersFromCompany(40.0, accuracyMeters: 480.0));

        $this->assertInstanceOf(PresencePing::class, $ping);
        $this->assertSame('480.00', $ping->accuracy);
    }

    #[Test]
    public function the_ping_belongs_to_the_session_that_is_open_now(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock('2026-09-02 14:00:00');

        $employee = $this->makeEmployee();
        $morning = $this->attendanceSession($employee, '08:00', '12:30');
        $afternoon = $this->attendanceSession($employee, '13:15');

        $ping = $this->record($employee, $this->readingAtCompany());

        $this->assertInstanceOf(PresencePing::class, $ping);
        $this->assertSame($afternoon->id, $ping->attendance_id);
        $this->assertNotSame($morning->id, $ping->attendance_id);
    }

    #[Test]
    public function a_session_left_open_on_an_earlier_day_does_not_collect_todays_pings(): void
    {
        // Yesterday's open session is a missing check-out, not a presence.
        // Hanging today's positions on it would invent hours nobody worked.
        $this->configureCompanyLocation();
        $today = $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $this->checkedIn($employee, $today->subDay());

        $this->assertNull($this->record($employee, $this->readingAtCompany()));
        $this->assertDatabaseCount('presence_pings', 0);
    }

    #[Test]
    public function nothing_is_recorded_while_the_company_location_is_unset(): void
    {
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '08:00');

        $this->assertNull($this->record($employee, $this->readingAtCompany()));
        $this->assertDatabaseCount('presence_pings', 0);
    }

    #[Test]
    public function one_employees_ping_never_lands_on_another_employees_session(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);

        $sara = $this->makeEmployee('sara@company.test');
        $omar = $this->makeEmployee('omar@company.test');

        $hers = $this->attendanceSession($sara, '08:00');
        $this->attendanceSession($omar, '08:30');

        $ping = $this->record($sara, $this->readingAtCompany());

        $this->assertSame($sara->id, $ping?->user_id);
        $this->assertSame($hers->id, $ping->attendance_id);
        $this->assertSame(1, PresencePing::query()->forUser($sara)->count());
        $this->assertSame(0, PresencePing::query()->forUser($omar)->count());
    }

    #[Test]
    public function the_stored_moment_is_the_servers_and_not_the_devices(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeRiyadhClock('2026-09-02 21:45:00');

        $employee = $this->makeEmployee();
        $this->attendanceSession($employee, '20:00');

        $ping = $this->record($employee, $this->readingAtCompany());

        $this->assertInstanceOf(CarbonImmutable::class, $ping?->created_at);
        $this->assertTrue($now->equalTo($ping->created_at));
    }

    private function record(User $employee, LocationReading $reading): ?PresencePing
    {
        return app(PresencePingRecorder::class)->record($employee, $reading);
    }
}
