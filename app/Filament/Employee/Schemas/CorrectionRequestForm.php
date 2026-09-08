<?php

declare(strict_types=1);

namespace App\Filament\Employee\Schemas;

use App\Enums\CorrectionReason;
use App\Models\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Attendance\AttendanceDaySummary;
use App\Services\Attendance\CorrectionQuota;
use App\Support\Attendance\SessionDuration;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The employee's correction form, in the order the question is actually
 * asked.
 *
 * Allowance, then the day, then *what the system already holds for that
 * day*, then which session, then the times, then why. The block of recorded
 * sessions sits above the time fields rather than below them because it is
 * the thing being overwritten: somebody proposing 08:00 deserves to see that
 * the device wrote 08:47 before they type it, not after they are refused.
 * When the day holds nothing the block says so in a sentence, which is the
 * answer to the commonest reason for opening this form at all.
 *
 * The remaining allowance leads for the same reason: it is the one fact that
 * decides whether the form is worth filling in, and a person who learns on
 * submit that they had none left has been made to type for nothing.
 *
 * Nothing here decides anything. Every rule belongs to
 * AttendanceCorrectionWorkflow, and each constraint on this form is that
 * rule said early: the picker's bounds are the workflow's date window, "both
 * times" is its IncompleteNewSession, and the required note is
 * CorrectionReason's own needsExplanation(). The form is feedback; the
 * service is the authority, and a hand-filled payload meets the same answer.
 */
final class CorrectionRequestForm
{
    /**
     * The longest note the column and the interface will carry.
     */
    public const int NOTE_MAX_LENGTH = 500;

    public static function configure(Schema $schema, User $employee): Schema
    {
        if (! self::isOpenTo($employee)) {
            return $schema->components([self::closedCallout()]);
        }

        $today = app(AttendanceCalendar::class)->today();

        return $schema
            ->components([
                self::allowanceCallout($employee),

                // native(false): an iPhone set to Arabic (Saudi Arabia)
                // draws input[type=date] on the Hijri calendar, and this
                // product prints Gregorian on every other screen. Two
                // calendars for one date is how somebody asks, in perfect
                // good faith, to correct a day three weeks from the one they
                // meant.
                DatePicker::make('attendance_date')
                    ->label(__('corrections.fields.attendance_date'))
                    ->helperText(__('corrections.helpers.attendance_date'))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->closeOnDateSelection()
                    ->required()
                    ->minDate(self::earliestDay($today)->format('Y-m-d'))
                    ->maxDate($today->format('Y-m-d'))
                    ->live()
                    ->validationMessages([
                        'required' => __('corrections.validation.date_required'),
                        'after_or_equal' => __('corrections.validation.date_range'),
                        'before_or_equal' => __('corrections.validation.date_future'),
                    ])
                    // Changing the day changes every answer below it. The
                    // session is re-chosen rather than kept: an id from
                    // yesterday names a session on another day, and the
                    // service would refuse it as not the employee's - a true
                    // sentence about the wrong mistake.
                    ->afterStateUpdated(function (Set $set, mixed $state) use ($employee): void {
                        $set('requested_check_in_at', null);
                        $set('requested_check_out_at', null);

                        $sessions = self::sessions($employee, $state);

                        $set(
                            'attendance_id',
                            $sessions->count() === 1 ? (string) $sessions->first()?->getKey() : null,
                        );
                    }),

                Placeholder::make('recorded')
                    ->label(__('corrections.sessions.heading'))
                    ->content(fn (Get $get): Htmlable => self::recordedSessions($employee, $get)),

                // Offered only when there is a choice to make. Exactly one
                // session is chosen by the picker above; none at all means
                // the request would create a session, which is what the two
                // required times below then are.
                Radio::make('attendance_id')
                    ->label(__('corrections.fields.session'))
                    ->helperText(__('corrections.helpers.session'))
                    ->options(fn (Get $get): array => self::sessionOptions($employee, $get))
                    ->visible(fn (Get $get): bool => self::sessions($employee, $get('attendance_date'))->count() >= 2)
                    ->required(fn (Get $get): bool => self::sessions($employee, $get('attendance_date'))->count() >= 2)
                    // A hidden field is not dehydrated, so without this the
                    // one session the picker just chose is dropped on the way
                    // out and the workflow is handed a request that names no
                    // session at all. On a day with a single session - the
                    // ordinary day - that turns "move my check-in" into
                    // "create a second session", which is refused when the
                    // times overlap and, when they do not, is granted as a
                    // duplicate. The value is the form's own, never the
                    // browser's: the day picker sets it and the service
                    // re-checks that the session belongs to the employee.
                    ->dehydratedWhenHidden()
                    ->validationMessages(['required' => __('corrections.validation.session_required')]),

                // Native pickers, unlike the date above: the OS time wheel is
                // the best target a thumb will ever get, and a clock has no
                // calendar system to render wrongly.
                TimePicker::make('requested_check_in_at')
                    ->label(__('corrections.fields.requested_check_in'))
                    ->helperText(__('corrections.helpers.times'))
                    ->seconds(false)
                    ->requiredWithout('requested_check_out_at')
                    ->required(fn (Get $get): bool => self::isNewSession($get))
                    ->validationMessages([
                        'required' => __('corrections.validation.both_times_required'),
                        'required_without' => __('corrections.validation.time_required'),
                    ]),

                TimePicker::make('requested_check_out_at')
                    ->label(__('corrections.fields.requested_check_out'))
                    ->seconds(false)
                    ->requiredWithout('requested_check_in_at')
                    ->required(fn (Get $get): bool => self::isNewSession($get))
                    ->after('requested_check_in_at')
                    ->validationMessages([
                        'required' => __('corrections.validation.both_times_required'),
                        'required_without' => __('corrections.validation.time_required'),
                        'after' => __('corrections.validation.check_out_after_check_in'),
                    ]),

                Select::make('reason')
                    ->label(__('corrections.fields.reason'))
                    ->options(CorrectionReason::options())
                    ->native(false)
                    ->required()
                    ->live()
                    ->validationMessages(['required' => __('corrections.validation.reason_required')]),

                Textarea::make('note')
                    ->label(__('corrections.fields.note'))
                    ->placeholder(__('corrections.placeholders.note'))
                    ->rows(3)
                    ->maxLength(self::NOTE_MAX_LENGTH)
                    // "Working remotely" and "a visit to an external site"
                    // assert something no record in this system can confirm,
                    // so the sentence explaining them is the whole of the
                    // evidence an approver has to go on.
                    ->required(fn (Get $get): bool => self::reasonNeedsExplanation($get))
                    ->helperText(fn (Get $get): string => self::reasonNeedsExplanation($get)
                        ? __('corrections.helpers.note_required')
                        : __('corrections.helpers.note'))
                    ->validationMessages([
                        'required' => __('corrections.validation.note_required'),
                        'max' => __('corrections.validation.note_max'),
                    ]),
            ]);
    }

    /**
     * Whether this employee has a form to fill in at all.
     *
     * Two different noes, each with its own sentence: the owner has switched
     * corrections off for everybody, or this employee has spent this month's
     * allowance. Neither hides the tile - a control that vanishes is a
     * control somebody hunts for - and both open onto the reason instead of
     * onto a form that cannot be sent.
     */
    public static function isOpenTo(User $employee): bool
    {
        $quota = app(CorrectionQuota::class);

        return $quota->allowance() > 0 && $quota->remainingFor($employee) > 0;
    }

    /**
     * The earliest day a correction may name: the first of last month.
     *
     * subMonthNoOverflow(), so the 31st of March asks about February instead
     * of landing back in March.
     */
    public static function earliestDay(CarbonImmutable $today): CarbonImmutable
    {
        return $today->subMonthNoOverflow()->startOfMonth();
    }

    private static function closedCallout(): Callout
    {
        $quota = app(CorrectionQuota::class);
        $allowance = $quota->allowance();

        if ($allowance === 0) {
            return Callout::make(__('corrections.employee.disabled_heading'))
                ->description(__('corrections.employee.disabled_body'))
                ->warning();
        }

        return Callout::make(__('corrections.employee.quota_spent_heading'))
            ->description(__('corrections.employee.quota_spent_body', [
                'allowance' => $allowance,
                'date' => $quota->resetsOn()->format('Y-m-d'),
            ]))
            ->warning();
    }

    private static function allowanceCallout(User $employee): Callout
    {
        $quota = app(CorrectionQuota::class);

        return Callout::make(__('corrections.employee.quota', [
            'remaining' => $quota->remainingFor($employee),
            'allowance' => $quota->allowance(),
            'date' => $quota->resetsOn()->format('Y-m-d'),
        ]))->info();
    }

    /**
     * The day's sessions, read through the same summary the attendance
     * screen uses - so the block printed here and the timeline printed there
     * can never disagree about what is recorded.
     *
     * @return Collection<int, Attendance>
     */
    private static function sessions(User $employee, mixed $date): Collection
    {
        if (! is_string($date) || trim($date) === '') {
            /** @var Collection<int, Attendance> $empty */
            $empty = new Collection;

            return $empty;
        }

        return AttendanceDaySummary::forEmployee(
            $employee,
            CarbonImmutable::parse($date, config('app.timezone'))->startOfDay(),
        )->sessions();
    }

    /**
     * @return array<int|string, string>
     */
    private static function sessionOptions(User $employee, Get $get): array
    {
        $options = [];
        $number = 0;

        foreach (self::sessions($employee, $get('attendance_date')) as $session) {
            $number++;

            $options[$session->getKey()] = __('corrections.sessions.option', ['number' => $number])
                .' — '.self::isolatedLtr(self::sessionRange($session));
        }

        return $options;
    }

    /**
     * A left-to-right run inside a right-to-left sentence.
     *
     * A radio option is a plain string with nowhere to hang dir="ltr", and
     * "08:03 - 12:31" dropped into an Arabic label is two number runs
     * separated by a neutral: the bidirectional algorithm orders them
     * right-to-left and prints the check-out first. On a screen whose entire
     * purpose is to say which moment is which, that is not a cosmetic bug.
     *
     * U+2066 LEFT-TO-RIGHT ISOLATE and U+2069 POP DIRECTIONAL ISOLATE do in
     * text what dir="ltr" does in markup, and travel through an option array
     * and Blade's escaping untouched.
     */
    private static function isolatedLtr(string $text): string
    {
        return "\u{2066}".$text."\u{2069}";
    }

    private static function recordedSessions(User $employee, Get $get): Htmlable
    {
        $date = $get('attendance_date');

        $rows = [];
        $number = 0;

        foreach (self::sessions($employee, $date) as $session) {
            $number++;
            $seconds = $session->durationInSeconds();

            $rows[] = [
                'number' => $number,
                'range' => self::sessionRange($session),
                'duration' => $seconds === null ? null : SessionDuration::format($seconds),
                // A corrected session must not wear the tag saying a location
                // put it there: that claim is exactly the one the correction
                // already withdrew.
                'isCorrected' => $session->isCorrected(),
            ];
        }

        return view('filament.employee.partials.recorded-sessions', [
            'hasDate' => is_string($date) && trim($date) !== '',
            'rows' => $rows,
        ]);
    }

    private static function sessionRange(Attendance $session): string
    {
        $timezone = (string) config('app.timezone');

        $checkOut = $session->check_out_at === null
            ? __('corrections.sessions.still_open')
            : $session->check_out_at->setTimezone($timezone)->format('H:i');

        return $session->check_in_at->setTimezone($timezone)->format('H:i').' – '.$checkOut;
    }

    /**
     * Whether the request would create a session rather than amend one, in
     * which case both times are needed.
     *
     * AttendanceCorrectionWorkflow's IncompleteNewSession rule, said early: a
     * correction that manufactured an *open* session on a past day would
     * produce a row nothing in this system can ever close, because
     * AttendanceWorkflow::checkOut() closes a session open today and there is
     * no other way to close one.
     */
    private static function isNewSession(Get $get): bool
    {
        $session = $get('attendance_id');

        return $session === null || $session === '';
    }

    private static function reasonNeedsExplanation(Get $get): bool
    {
        $reason = $get('reason');

        if (! is_string($reason)) {
            return false;
        }

        return CorrectionReason::tryFrom($reason)?->needsExplanation() ?? false;
    }
}
