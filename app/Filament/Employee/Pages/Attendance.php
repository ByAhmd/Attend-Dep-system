<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceStatus;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDaySummary;
use App\Services\Attendance\AttendanceWorkflow;
use App\Services\Attendance\PresencePingRecorder;
use App\Services\Geolocation\LocationReadingValidator;
use App\Support\Attendance\SessionDuration;
use App\Support\Geo\Meters;
use Carbon\CarbonImmutable;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use DanHarrin\LivewireRateLimiting\WithRateLimiting;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Widgets\Widget;
use Illuminate\Validation\ValidationException;

/**
 * The employee's one screen: today's sessions, Check In, Check Out, history.
 *
 * A day is a list of sessions rather than a single pair of times, because an
 * employee who leaves at noon checks out and checks in again on their
 * return. The screen therefore says what state they are in now, what the day
 * has held so far, and how long they have been inside; the two buttons
 * follow the rule the workflow enforces, one of them available at a time.
 *
 * Answers the panel root the way Filament's Dashboard does, so signing in
 * lands here with nothing to navigate. The browser hands over three numbers
 * (latitude, longitude, accuracy) and nothing else; every rule, timestamp
 * and distance comes from AttendanceWorkflow, and this class only turns its
 * answers into a sentence on the page.
 */
final class Attendance extends Page
{
    use WithRateLimiting;

    /**
     * The presence-ping throttle: attempts, and the window they are counted
     * in. Generous on purpose and separate from the check-in allowance -
     * the page pings once per configured interval (five minutes by
     * default), so twenty in five minutes leaves room for reloads, a second
     * tab and a retry after a lost connection, while a script posting every
     * second is refused within twenty of them.
     */
    private const int PING_ATTEMPTS = 20;

    private const int PING_WINDOW_SECONDS = 300;

    protected static ?string $slug = 'attendance';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.employee.pages.attendance';

    public ?string $feedbackMessage = null;

    public string $feedbackStatus = 'info';

    /**
     * Whether a session is open right now, published to the browser so the
     * ping loop knows when to run and when to stop.
     *
     * A synchronised property rather than an event: Livewire snapshots the
     * component after every render, so the browser learns that a session
     * opened or closed on the same round trip that changed it, and the two
     * features stay independent of each other.
     */
    public bool $sessionIsOpen = false;

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public function getTitle(): string
    {
        return __('attendance.page.title');
    }

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    public function checkIn(array $reading): void
    {
        $this->attempt(AttendanceAction::CheckIn, $reading);
    }

    /**
     * @param  array<string, mixed>  $reading
     */
    public function checkOut(array $reading): void
    {
        $this->attempt(AttendanceAction::CheckOut, $reading);
    }

    /**
     * One presence ping: where the device says it is, while a session is
     * open.
     *
     * Silent by design. A ping is a supporting observation the employee did
     * not ask for, so nothing it does - being throttled, arriving malformed,
     * or finding no open session to belong to - may put a message on the
     * screen, disable a button or stand between the employee and the two
     * that matter. It records what it can and says nothing.
     *
     * The throttle is its own bucket (the key is built from the method
     * name), so a day of pings never spends the check-in allowance and a
     * flood of pings never locks an employee out of checking in.
     *
     * @param  array<string, mixed>  $reading
     */
    public function ping(array $reading): void
    {
        try {
            $this->rateLimit(self::PING_ATTEMPTS, decaySeconds: self::PING_WINDOW_SECONDS, method: 'ping');
        } catch (TooManyRequestsException) {
            return;
        }

        try {
            $location = app(LocationReadingValidator::class)->validate($reading);
        } catch (ValidationException) {
            return;
        }

        app(PresencePingRecorder::class)->record($this->employee(), $location);
    }

    /**
     * @return array<class-string<Widget>>
     */
    protected function getFooterWidgets(): array
    {
        return [
            AttendanceHistoryWidget::class,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $employee = $this->employee();
        $today = app(AttendanceCalendar::class)->today();
        $settings = AttendanceSetting::current();
        $day = AttendanceDaySummary::forEmployee($employee, $today);

        // The badge reports the last thing that happened: inside while a
        // session is open, otherwise checked out once the day holds one,
        // otherwise not checked in yet. Today's sessions can only be open
        // or closed - "missing check-out" belongs to earlier days and never
        // reaches this screen.
        $status = $day->latestSession()?->status();

        // The one query that produced the day also decides whether the
        // browser should be pinging, so the buttons and the ping loop can
        // never disagree about whether a session is open.
        $this->sessionIsOpen = $day->isCheckedIn();

        return [
            'employeeName' => $employee->name,
            'todayLabel' => $today->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY'),
            'stateLabel' => __('attendance.page.status.'.($status instanceof AttendanceStatus ? $status->value : 'not_checked_in')),
            'stateColor' => $status instanceof AttendanceStatus ? $status->color() : 'gray',
            'sessions' => $this->sessionRows($day),
            'sessionCount' => (string) $day->sessionCount(),
            'totalInside' => SessionDuration::format($day->secondsInside()),
            'isLocationConfigured' => $settings->isConfigured(),
            'radiusMeters' => $settings->radius_meters,
            // Coming back after a check-out is the point of the feature;
            // only a session still open stands in the way of a check-in.
            'canCheckIn' => $settings->isConfigured() && ! $day->isCheckedIn(),
            'canCheckOut' => $settings->isConfigured() && $day->isCheckedIn(),
            // Milliseconds, because that is what the browser's timers take.
            'pingIntervalMs' => ((int) config('attendance.ping_interval_seconds')) * 1000,
        ];
    }

    /**
     * Today's sessions as the view reads them: numbered in the order they
     * happened, with every time already formatted in the attendance
     * timezone, so the template only prints.
     *
     * @return list<array{number: int, checkIn: string, checkOut: string, duration: string}>
     */
    private function sessionRows(AttendanceDaySummary $day): array
    {
        $rows = [];
        $number = 0;

        foreach ($day->sessions() as $session) {
            $number++;

            $rows[] = [
                'number' => $number,
                'checkIn' => $this->formatTime($session->check_in_at),
                'checkOut' => $this->formatTime($session->check_out_at),
                'duration' => SessionDuration::format($session->durationInSeconds()),
            ];
        }

        return $rows;
    }

    /**
     * One path for both buttons: throttle, validate, run the workflow, and
     * report. The rate limit is checked before validation so a flood of
     * malformed payloads costs the same as a flood of real ones.
     *
     * @param  array<string, mixed>  $reading
     */
    private function attempt(AttendanceAction $action, array $reading): void
    {
        try {
            $this->rateLimit(10, method: $action->value);
        } catch (TooManyRequestsException) {
            $this->feedback(__('attendance.feedback.too_many_attempts'), 'danger');

            return;
        }

        try {
            $location = app(LocationReadingValidator::class)->validate($reading);
        } catch (ValidationException $exception) {
            $this->feedback((string) $exception->validator->errors()->first(), 'danger');

            return;
        }

        $workflow = app(AttendanceWorkflow::class);
        $employee = $this->employee();

        try {
            $attendance = $action === AttendanceAction::CheckIn
                ? $workflow->checkIn($employee, $location)
                : $workflow->checkOut($employee, $location);
        } catch (AttendanceRejectedException $exception) {
            $this->feedback($exception->getMessage(), 'danger');

            Notification::make()
                ->title($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $distance = $action === AttendanceAction::CheckIn
            ? $attendance->check_in_distance_from_company
            : $attendance->check_out_distance_from_company;

        $message = __('attendance.feedback.'.$action->value.'_success')
            .' '
            .__('attendance.feedback.distance', ['distance' => Meters::format($distance)]);

        $this->feedback($message, 'success');

        Notification::make()
            ->title($message)
            ->success()
            ->send();

        // The history table is its own Livewire component; tell it today
        // changed so the new row appears without a reload.
        $this->dispatch('attendance-recorded')->to(AttendanceHistoryWidget::class);
    }

    private function feedback(string $message, string $status): void
    {
        $this->feedbackMessage = $message;
        $this->feedbackStatus = $status;
    }

    /**
     * Throttle per account, not per address. The package keys on the
     * client IP, and a whole office checks in from one public address at
     * eight o'clock - the eleventh person would have been refused for the
     * ten before them. Every request here is authenticated, so the account
     * is the honest unit.
     *
     * @param  string|null  $method
     * @param  string|null  $component
     */
    protected function getRateLimitKey($method, $component = null): string
    {
        $component ??= self::class;

        return 'livewire-rate-limiter:'.sha1($component.'|'.$method.'|user:'.$this->employee()->id);
    }

    /**
     * The signed-in account. The auth middleware guarantees one; anything
     * else reaching here is a request that should never have been served.
     */
    private function employee(): User
    {
        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            abort(403);
        }

        return $user;
    }

    private function formatTime(?CarbonImmutable $time): string
    {
        if (! $time instanceof CarbonImmutable) {
            return __('attendance.page.not_recorded');
        }

        return $time->setTimezone((string) config('app.timezone'))->format('H:i');
    }
}
