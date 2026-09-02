<?php

declare(strict_types=1);

namespace App\Filament\Employee\Pages;

use App\Enums\AttendanceAction;
use App\Enums\AttendanceStatus;
use App\Exceptions\Attendance\AttendanceRejectedException;
use App\Filament\Employee\Widgets\AttendanceHistoryWidget;
use App\Models\Attendance as AttendanceRecord;
use App\Models\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceWorkflow;
use App\Services\Geolocation\LocationReadingValidator;
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
 * The employee's one screen: today's status, Check In, Check Out, history.
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

    protected static ?string $slug = 'attendance';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.employee.pages.attendance';

    public ?string $feedbackMessage = null;

    public string $feedbackStatus = 'info';

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

        $attendance = AttendanceRecord::query()
            ->where('user_id', $employee->id)
            ->forDate($today)
            ->first();

        // Today's record can only be open or closed; "missing check-out"
        // belongs to earlier days and never reaches this screen.
        $status = $attendance?->status();

        return [
            'employeeName' => $employee->name,
            'todayLabel' => $today->locale(app()->getLocale())->isoFormat('dddd, D MMMM YYYY'),
            'stateLabel' => __('attendance.page.status.'.($status instanceof AttendanceStatus ? $status->value : 'not_checked_in')),
            'stateColor' => $status instanceof AttendanceStatus ? $status->color() : 'gray',
            'checkInTime' => $this->formatTime($attendance?->check_in_at),
            'checkOutTime' => $this->formatTime($attendance?->check_out_at),
            'isLocationConfigured' => $settings->isConfigured(),
            'radiusMeters' => $settings->radius_meters,
            'canCheckIn' => $settings->isConfigured() && ! $attendance instanceof AttendanceRecord,
            'canCheckOut' => $settings->isConfigured() && $attendance instanceof AttendanceRecord && $attendance->isOpen(),
        ];
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
    }

    private function feedback(string $message, string $status): void
    {
        $this->feedbackMessage = $message;
        $this->feedbackStatus = $status;
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
