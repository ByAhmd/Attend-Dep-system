<?php

declare(strict_types=1);

namespace Tests\Feature\Employee;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceRejectionReason;
use App\Filament\Employee\Pages\Attendance;
use App\Models\Attendance as AttendanceRecord;
use App\Models\User;
use App\Support\Geo\LocationReading;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesAttendanceFixtures;
use Tests\TestCase;

/**
 * The employee screen as a Livewire component: what it shows, and what the
 * two buttons do with what the browser sends.
 *
 * Every timestamp asserted here is compared with a frozen server clock, and
 * every payload carries only the three numbers the browser is allowed to
 * send - plus, in one test, the numbers it is not, to prove they are ignored.
 */
final class AttendancePageTest extends TestCase
{
    use CreatesAttendanceFixtures;
    use RefreshDatabase;

    private const string FROZEN_NOW = '2026-09-02 09:15:00';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel('employee');
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedPayloads(): array
    {
        return [
            'non-numeric latitude' => [
                ['latitude' => 'abc', 'longitude' => 46.6753, 'accuracy' => 10.0],
                'attendance.validation.latitude',
            ],
            'missing accuracy' => [
                ['latitude' => 24.7136, 'longitude' => 46.6753],
                'attendance.validation.accuracy',
            ],
            'latitude beyond 90' => [
                ['latitude' => 91, 'longitude' => 46.6753, 'accuracy' => 10.0],
                'attendance.validation.latitude',
            ],
        ];
    }

    #[Test]
    public function the_page_renders_for_an_active_employee_with_their_name_and_status(): void
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        Livewire::test(Attendance::class)
            ->assertOk()
            ->assertSee($employee->name)
            ->assertSee(__('attendance.page.status.not_checked_in'));
    }

    #[Test]
    public function checking_in_inside_the_radius_records_today_with_the_servers_clock(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeClock();
        $employee = $this->signInEmployee();

        $message = __('attendance.feedback.check_in_success').' '.__('attendance.feedback.distance', ['distance' => '80']);

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payload($this->readingMetersFromCompany(80.0)))
            ->assertSet('feedbackStatus', 'success')
            ->assertSet('feedbackMessage', $message)
            ->assertNotified($message);

        $attendance = AttendanceRecord::query()->where('user_id', $employee->id)->sole();

        $this->assertSame('2026-09-02', $attendance->attendance_date->toDateString());
        $this->assertTrue($now->equalTo($attendance->check_in_at));
        $this->assertSame('80.00', $attendance->check_in_distance_from_company);
        $this->assertNull($attendance->check_out_at);
    }

    #[Test]
    public function a_reading_outside_the_radius_creates_nothing_and_names_the_distance(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payload($this->readingMetersFromCompany(200.0)))
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.rejections.outside_allowed_area', [
                'radius' => '150',
                'distance' => '200',
            ]));

        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function a_poor_accuracy_reading_is_refused_and_recorded_as_a_rejection(): void
    {
        $this->configureCompanyLocation();
        $employee = $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payload($this->readingMetersFromCompany(80.0, 500.0)))
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.rejections.insufficient_accuracy', ['accuracy' => '500']));

        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseHas('attendance_rejections', [
            'user_id' => $employee->id,
            'action' => AttendanceAction::CheckIn->value,
            'reason' => AttendanceRejectionReason::InsufficientAccuracy->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[Test]
    #[DataProvider('malformedPayloads')]
    public function a_malformed_payload_creates_nothing_and_shows_the_validation_message(array $payload, string $messageKey): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __($messageKey));

        $this->assertDatabaseCount('attendances', 0);
        $this->assertDatabaseCount('attendance_rejections', 0);
    }

    #[Test]
    public function checking_in_twice_reports_the_existing_check_in(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        $payload = $this->payload($this->readingMetersFromCompany(80.0));

        Livewire::test(Attendance::class)
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'success')
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.rejections.already_checked_in'));

        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function checking_out_without_a_check_in_is_refused(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkOut', $this->payload($this->readingMetersFromCompany(80.0)))
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.rejections.not_checked_in'));

        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function checking_out_inside_the_radius_closes_todays_record(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeClock();
        $employee = $this->signInEmployee();
        $attendance = $this->checkedIn($employee);

        $message = __('attendance.feedback.check_out_success').' '.__('attendance.feedback.distance', ['distance' => '80']);

        Livewire::test(Attendance::class)
            ->call('checkOut', $this->payload($this->readingMetersFromCompany(80.0)))
            ->assertSet('feedbackStatus', 'success')
            ->assertSet('feedbackMessage', $message)
            ->assertNotified($message)
            ->assertSee(__('attendance.page.status.checked_out'));

        $attendance->refresh();

        $this->assertTrue($now->equalTo($attendance->check_out_at));
        $this->assertSame('80.00', $attendance->check_out_distance_from_company);
    }

    #[Test]
    public function the_eleventh_rapid_attempt_is_rate_limited(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        $payload = $this->payload($this->readingMetersFromCompany(80.0));
        $component = Livewire::test(Attendance::class);

        foreach (range(1, 10) as $attempt) {
            $component->call('checkIn', $payload);

            $this->assertNotSame(
                __('attendance.feedback.too_many_attempts'),
                $component->get('feedbackMessage'),
                "Attempt {$attempt} was throttled too early.",
            );
        }

        $component
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.feedback.too_many_attempts'));

        $this->assertDatabaseCount('attendances', 1);
    }

    #[Test]
    public function a_client_supplied_time_or_distance_is_ignored(): void
    {
        $this->configureCompanyLocation();
        $now = $this->freezeClock();
        $employee = $this->signInEmployee();

        $payload = $this->payload($this->readingMetersFromCompany(80.0)) + [
            'check_in_at' => '2020-01-01 00:00:00',
            'distance' => 1,
        ];

        Livewire::test(Attendance::class)
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'success');

        $attendance = AttendanceRecord::query()->where('user_id', $employee->id)->sole();

        $this->assertTrue($now->equalTo($attendance->check_in_at));
        $this->assertSame('80.00', $attendance->check_in_distance_from_company);
    }

    #[Test]
    public function the_buttons_are_disabled_until_the_company_location_is_configured(): void
    {
        $this->signInEmployee();

        $component = Livewire::test(Attendance::class);

        $component
            ->assertOk()
            ->assertSee(__('attendance.page.location_not_configured'))
            ->assertDontSee(__('attendance.page.radius_hint', ['radius' => 150]));

        // Both buttons carry the disabled attribute itself, not merely the
        // Alpine binding or the wire:loading hook that mention the word.
        $this->assertSame(2, $this->disabledButtonCount($component->html()));

        // A call that bypasses the disabled buttons is refused by the server.
        $component
            ->call('checkIn', $this->payload($this->readingAtCompany()))
            ->assertSet('feedbackStatus', 'danger')
            ->assertSet('feedbackMessage', __('attendance.rejections.location_not_configured'));

        $this->assertDatabaseCount('attendances', 0);
    }

    #[Test]
    public function a_successful_check_in_tells_the_history_widget_to_refresh(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payload($this->readingMetersFromCompany(80.0)))
            ->assertSet('feedbackStatus', 'success')
            ->assertDispatched('attendance-recorded');
    }

    #[Test]
    public function a_refused_check_in_leaves_the_history_widget_alone(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        Livewire::test(Attendance::class)
            ->call('checkIn', $this->payload($this->readingMetersFromCompany(500.0)))
            ->assertSet('feedbackStatus', 'danger')
            ->assertNotDispatched('attendance-recorded');
    }

    #[Test]
    public function only_the_check_in_button_is_enabled_before_the_first_check_in(): void
    {
        $this->configureCompanyLocation();
        $this->signInEmployee();

        $component = Livewire::test(Attendance::class);

        $component->assertOk();

        $this->assertSame(1, $this->disabledButtonCount($component->html()));
    }

    #[Test]
    public function the_throttle_is_per_account_not_per_address(): void
    {
        // Every employee in the office shares one public IP address at
        // eight o'clock; one colleague's ten attempts must not lock out
        // the next person in the queue.
        $this->configureCompanyLocation();
        $payload = $this->payload($this->readingMetersFromCompany(80.0));

        $first = $this->makeEmployee();
        $this->actingAs($first);
        $component = Livewire::test(Attendance::class);

        foreach (range(1, 10) as $attempt) {
            $component->call('checkIn', $payload);
        }

        $component
            ->call('checkIn', $payload)
            ->assertSet('feedbackMessage', __('attendance.feedback.too_many_attempts'));

        $second = $this->makeEmployee();
        $this->actingAs($second);

        Livewire::test(Attendance::class)
            ->call('checkIn', $payload)
            ->assertSet('feedbackStatus', 'success');

        $this->assertDatabaseHas('attendances', ['user_id' => $second->id]);
    }

    /**
     * Buttons rendered with the disabled attribute. The Alpine binding
     * (x-bind:disabled) and Filament's wire:loading.attr="disabled" also
     * contain the word, so only a bare attribute preceded by whitespace
     * counts.
     */
    private function disabledButtonCount(string $html): int
    {
        return preg_match_all('/<button\b[^>]*\sdisabled(?:\s|>|=)/', $html);
    }

    private function signInEmployee(): User
    {
        $employee = $this->makeEmployee();
        $this->actingAs($employee);

        return $employee;
    }

    private function freezeClock(): CarbonImmutable
    {
        $now = CarbonImmutable::parse(self::FROZEN_NOW, 'Asia/Riyadh');

        Carbon::setTestNow($now);

        return $now;
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
