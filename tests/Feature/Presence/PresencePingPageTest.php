<?php

declare(strict_types=1);

namespace Tests\Feature\Presence;

use App\Filament\Employee\Pages\Attendance;
use App\Models\PresencePing;
use App\Models\User;
use App\Support\Geo\LocationReading;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The ping endpoint on the employee screen.
 *
 * A ping is the one thing on this page the employee did not ask for, so
 * every test here also asserts what the screen does NOT do: no message, no
 * notification, no button touched, whatever the outcome. The throttle is
 * exercised in its own right because it must be generous enough for a
 * reading every few minutes and separate enough that a flood of pings can
 * never stand between an employee and their check-in.
 */
final class PresencePingPageTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-02 10:20:00';

    /**
     * The page allows twenty pings per five minutes.
     */
    private const int PING_ALLOWANCE = 20;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'non-numeric latitude' => [['latitude' => 'here', 'longitude' => 46.6753, 'accuracy' => 10.0]],
            'missing accuracy' => [['latitude' => 24.7136, 'longitude' => 46.6753]],
            'longitude beyond 180' => [['latitude' => 24.7136, 'longitude' => 181, 'accuracy' => 10.0]],
            'nothing at all' => [[]],
        ];
    }

    #[Test]
    public function a_ping_while_checked_in_is_stored_against_the_open_session(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $session = $this->attendanceSession($employee, '08:00');

        Livewire::test(Attendance::class)
            ->call('ping', $this->payload($this->readingMetersFromCompany(60.0)))
            ->assertOk();

        $ping = PresencePing::query()->sole();

        $this->assertSame($employee->id, $ping->user_id);
        $this->assertSame($session->id, $ping->attendance_id);
        $this->assertSame('60.00', $ping->distance_from_company);
        $this->assertTrue($ping->is_inside);
        $this->assertTrue($now->equalTo($ping->created_at));
    }

    #[Test]
    public function a_ping_says_nothing_to_the_employee(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $this->attendanceSession($employee, '08:00');

        Livewire::test(Attendance::class)
            ->call('ping', $this->payload($this->readingMetersFromCompany(900.0)))
            ->assertSet('feedbackMessage', null)
            ->assertSet('feedbackStatus', 'info')
            ->assertNotDispatched('attendance-recorded')
            ->assertNotNotified();

        // Recorded and flagged all the same - silence is for the employee,
        // not for the record.
        $this->assertFalse(PresencePing::query()->sole()->is_inside);
    }

    #[Test]
    public function a_ping_without_an_open_session_stores_nothing(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('ping', $this->payload($this->readingAtCompany()))
            ->assertSet('feedbackMessage', null)
            ->assertOk();

        $this->assertDatabaseCount('presence_pings', 0);
    }

    #[Test]
    public function a_ping_outside_the_radius_is_stored_and_flagged(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $this->attendanceSession($employee, '08:00');

        Livewire::test(Attendance::class)
            ->call('ping', $this->payload($this->readingMetersFromCompany(420.0)));

        $ping = PresencePing::query()->sole();

        $this->assertFalse($ping->is_inside);
        $this->assertSame('420.00', $ping->distance_from_company);
    }

    #[Test]
    public function a_client_supplied_distance_or_time_is_ignored(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $this->attendanceSession($employee, '08:00');

        $payload = $this->payload($this->readingMetersFromCompany(300.0)) + [
            'distance_from_company' => 1.0,
            'is_inside' => true,
            'created_at' => '2020-01-01 00:00:00',
        ];

        Livewire::test(Attendance::class)->call('ping', $payload);

        $ping = PresencePing::query()->sole();

        // The three hundred metres the server measured, not the one metre
        // and the "inside" the browser asked for.
        $this->assertSame('300.00', $ping->distance_from_company);
        $this->assertFalse($ping->is_inside);
        $this->assertTrue($now->equalTo($ping->created_at));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('malformedPayloads')]
    public function a_malformed_payload_is_refused_in_silence(array $payload): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $this->attendanceSession($employee, '08:00');

        Livewire::test(Attendance::class)
            ->call('ping', $payload)
            ->assertSet('feedbackMessage', null)
            ->assertSet('feedbackStatus', 'info')
            ->assertOk();

        $this->assertDatabaseCount('presence_pings', 0);
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function a_flood_of_pings_is_refused_and_the_next_interval_is_accepted_again(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();
        $this->attendanceSession($employee, '08:00');

        $payload = $this->payload($this->readingMetersFromCompany(60.0));
        $component = Livewire::test(Attendance::class);

        foreach (range(1, self::PING_ALLOWANCE) as $attempt) {
            $component->call('ping', $payload);

            $this->assertDatabaseCount('presence_pings', $attempt);
        }

        // The flood stops here, and stops silently.
        $component
            ->call('ping', $payload)
            ->assertSet('feedbackMessage', null);

        $this->assertDatabaseCount('presence_pings', self::PING_ALLOWANCE);

        // Five minutes later - one ordinary interval - the page is welcome
        // again, which is the whole point of a window this generous.
        $this->freezeRiyadhClock('2026-09-02 10:26:00');

        $component->call('ping', $payload);

        $this->assertDatabaseCount('presence_pings', self::PING_ALLOWANCE + 1);
    }

    #[Test]
    public function pinging_never_spends_the_check_in_allowance(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $employee = $this->signInEmployee();

        $payload = $this->payload($this->readingMetersFromCompany(60.0));
        $component = Livewire::test(Attendance::class);

        // Far past the ping allowance, and none of it belongs to check-in.
        foreach (range(1, self::PING_ALLOWANCE + 5) as $ignored) {
            $component->call('ping', $payload);
        }

        $component
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'success');

        $this->assertDatabaseHas('attendances', ['user_id' => $employee->id]);
    }

    #[Test]
    public function checking_in_and_out_tells_the_browser_when_to_ping(): void
    {
        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signInEmployee();

        $payload = $this->payload($this->readingMetersFromCompany(60.0));

        Livewire::test(Attendance::class)
            ->assertSet('sessionIsOpen', false)
            ->call('checkIn', $payload)
            ->assertSet('sessionIsOpen', true)
            ->call('checkOut', $payload)
            ->assertSet('sessionIsOpen', false);
    }

    #[Test]
    public function the_page_carries_the_configured_interval_and_says_that_it_records(): void
    {
        config()->set('attendance.ping_interval_seconds', 300);

        $this->configureCompanyLocation();
        $this->freezeRiyadhClock(self::FROZEN_NOW);
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee('pingIntervalMs: 300000', false)
            ->assertSee(__('presence.employee.hint'));
    }

    private function signInEmployee(): User
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        return $employee;
    }

    /**
     * Exactly what the browser sends: three numbers and nothing else.
     *
     * @return array<string, float>
     */
    private function payload(LocationReading $reading): array
    {
        return [
            'latitude' => $reading->coordinates->latitude,
            'longitude' => $reading->coordinates->longitude,
            'accuracy' => $reading->accuracyMeters,
        ];
    }
}
