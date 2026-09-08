<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceStatus;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Employee\Actions\RequestCorrectionAction;
use App\Filament\Employee\Actions\RequestLeaveAction;
use App\Filament\Employee\Concerns\BuildsQuickActionTiles;
use App\Filament\Employee\Concerns\ThrottlesPerAccount;
use App\Filament\Employee\Contracts\ThrottlesRequests;
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
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Enums\Width;
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
 *
 * The two request forms are mounted here as modals and reached from a flat
 * band of tiles below the card - never above it. Checking in is why this
 * page exists, and a form nobody opens weekly must not compete with the
 * button somebody presses every morning.
 */
final class Attendance extends Page implements ThrottlesRequests
{
    use BuildsQuickActionTiles;
    use ThrottlesPerAccount;

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
     * One column, phone-shaped, on every screen.
     *
     * This page is designed for a thumb at the door in the morning, and a
     * desktop browser is the same page made wider - not a different one. A
     * narrow measure keeps the card, the timeline and the history table in a
     * single readable column instead of stranding a phone layout in the
     * corner of a 1400px window.
     */
    public function getMaxContentWidth(): Width
    {
        return Width::TwoExtraLarge;
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

    public function requestCorrectionAction(): Action
    {
        return RequestCorrectionAction::make($this->employee());
    }

    public function requestLeaveAction(): Action
    {
        return RequestLeaveAction::make($this->employee());
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

        // The screen reports the last thing that happened: inside while a
        // session is open, otherwise checked out once the day holds one,
        // otherwise not checked in yet. Today's sessions can only be open
        // or closed - "missing check-out" belongs to earlier days and never
        // reaches this screen.
        $status = $day->latestSession()?->status();
        $state = $status instanceof AttendanceStatus ? $status->value : 'not_checked_in';

        // The one query that produced the day also decides whether the
        // browser should be pinging, so the buttons and the ping loop can
        // never disagree about whether a session is open.
        $this->sessionIsOpen = $day->isCheckedIn();

        // Coming back after a check-out is the point of the feature; only a
        // session still open stands in the way of a check-in.
        $canCheckIn = $settings->isConfigured() && ! $day->isCheckedIn();
        $canCheckOut = $settings->isConfigured() && $day->isCheckedIn();

        return [
            'employeeName' => $employee->name,
            'todayLabel' => $today->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY'),
            'state' => $state,
            'stateLabel' => __('attendance.page.status.'.$state),
            'stateCaption' => $this->stateCaption($state, $day),
            'sessions' => $this->sessionRows($day),
            'sessionCount' => (string) $day->sessionCount(),
            'totalInside' => SessionDuration::format($day->secondsInside()),
            'isLocationConfigured' => $settings->isConfigured(),
            'radiusMeters' => $settings->radius_meters,
            'canCheckIn' => $canCheckIn,
            'canCheckOut' => $canCheckOut,
            'actions' => $this->actionButtons($canCheckIn, $canCheckOut),
            // Milliseconds, because that is what the browser's timers take.
            'pingIntervalMs' => ((int) config('attendance.ping_interval_seconds')) * 1000,
            'tiles' => $this->attendanceScreenTiles($employee),
        ];
    }

    /**
     * The sentence under the state, which turns a label into a fact the
     * employee can check against their own memory of the morning.
     *
     * Every time it names comes from the sessions already loaded above, so
     * saying more costs no extra query.
     */
    private function stateCaption(string $state, AttendanceDaySummary $day): string
    {
        return match ($state) {
            AttendanceStatus::CheckedIn->value => __('attendance.page.state_caption.checked_in', [
                'time' => $this->formatTime($day->openSession()?->check_in_at),
            ]),
            AttendanceStatus::CheckedOut->value => __('attendance.page.state_caption.checked_out', [
                'time' => $this->formatTime($day->latestSession()?->check_out_at),
            ]),
            default => __('attendance.page.state_caption.not_checked_in'),
        };
    }

    /**
     * The two buttons, the one the employee can actually press first.
     *
     * Exactly one of them is available at a time (and neither before the
     * company location is set), so the order is what makes the screen
     * obvious: the live action leads and is drawn large, the other follows
     * as a disabled reminder that it exists. Both are always rendered -
     * hiding the unavailable one would leave an employee wondering where
     * check-out went.
     *
     * `key` is the Livewire method the browser calls and the value the
     * Alpine component tracks as `pending`; `alpineFlag` is the property it
     * re-checks before every submit, so the disabled state survives a round
     * trip that changed it.
     *
     * @return list<array{key: string, label: string, alpineFlag: string, enabled: bool}>
     */
    private function actionButtons(bool $canCheckIn, bool $canCheckOut): array
    {
        $checkIn = [
            'key' => 'checkIn',
            'label' => __('attendance.actions.check_in'),
            'alpineFlag' => 'canCheckIn',
            'enabled' => $canCheckIn,
        ];

        $checkOut = [
            'key' => 'checkOut',
            'label' => __('attendance.actions.check_out'),
            'alpineFlag' => 'canCheckOut',
            'enabled' => $canCheckOut,
        ];

        return $canCheckOut ? [$checkOut, $checkIn] : [$checkIn, $checkOut];
    }

    /**
     * Today's sessions as the view reads them: numbered in the order they
     * happened, with every time already formatted in the attendance
     * timezone, so the template only prints.
     *
     * A session still running ends at "now" rather than at the dash a
     * missing check-out would show - it is the one row on the screen that
     * has not finished, and saying so in a word keeps the timeline readable
     * without relying on the colour of its marker.
     *
     * @return list<array{number: int, checkIn: string, checkOut: string, duration: string, isOpen: bool, isCorrected: bool}>
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
                'checkOut' => $session->isOpen()
                    ? __('attendance.page.session_open')
                    : $this->formatTime($session->check_out_at),
                'duration' => SessionDuration::format($session->durationInSeconds()),
                'isOpen' => $session->isOpen(),
                // Read off the row already loaded, so saying that a time was
                // corrected costs nothing and the employee is never shown a
                // moment this system verified by location when it did not.
                'isCorrected' => $session->isCorrected(),
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
     * The signed-in account. The auth middleware guarantees one; anything
     * else reaching here is a request that should never have been served.
     *
     * Protected and not public: a public method on a Livewire component is
     * an endpoint the browser may call, and this one returns a person.
     */
    protected function employee(): User
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
