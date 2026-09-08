<?php

declare(strict_types=1);

namespace App\Filament\Employee\Schemas;

use App\Enums\LeaveType;
use App\Models\LeaveRequest;
use App\Services\Attendance\AttendanceCalendar;
use App\Services\Leave\LeaveAttachmentStore;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * The employee's leave form.
 *
 * Type, when, how the days are meant, why, and - if there is one - the
 * document that supports it.
 *
 * "Leaving and coming back" is a toggle rather than a seventh leave type,
 * because it is not a kind of leave: it is hours away on a day the employee
 * is otherwise at work. Turning it on collapses the range to the single day
 * it describes and hides the end picker, since a control with exactly one
 * legal value is not a control - it is a thing to get wrong.
 *
 * The day count is a placeholder and not a field. It is the figure the
 * employee actually wants confirmed before sending, and printing it beside
 * the pickers is cheaper than making them count on their fingers and
 * cheaper still than an administrator rejecting a request that meant four
 * days and said fourteen.
 *
 * Every bound here is LeaveRequestWorkflow's, said early. The service
 * refuses the same request with the same sentence when a payload arrives
 * without ever passing through this form.
 */
final class LeaveRequestForm
{
    public const int REASON_MIN_LENGTH = 10;

    public const int REASON_MAX_LENGTH = 1000;

    public static function configure(Schema $schema): Schema
    {
        $today = app(AttendanceCalendar::class)->today();
        $earliest = self::earliestStart($today);
        $latest = self::latestStart($today);

        return $schema
            ->components([
                Select::make('type')
                    ->label(__('leave.fields.type'))
                    ->options(LeaveType::options())
                    ->native(false)
                    ->required()
                    ->validationMessages(['required' => __('leave.validation.type_required')]),

                DatePicker::make('starts_on')
                    ->label(__('leave.fields.starts_on'))
                    ->helperText(__('leave.helpers.range'))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->closeOnDateSelection()
                    ->required()
                    ->minDate($earliest->format('Y-m-d'))
                    ->maxDate($latest->format('Y-m-d'))
                    ->live()
                    ->validationMessages([
                        'required' => __('leave.validation.starts_required'),
                        'after_or_equal' => __('leave.validation.starts_too_early'),
                        'before_or_equal' => __('leave.validation.starts_too_late'),
                    ]),

                Toggle::make('is_exit_and_return')
                    ->label(__('leave.fields.is_exit_and_return'))
                    ->helperText(__('leave.helpers.is_exit_and_return'))
                    ->default(false)
                    ->live()
                    // The end date follows the start the moment the toggle
                    // goes on, so the figure below and the payload agree with
                    // the picker the employee can no longer see.
                    ->afterStateUpdated(function (Get $get, Set $set, bool $state): void {
                        if ($state) {
                            $set('ends_on', $get('starts_on'));
                        }
                    }),

                DatePicker::make('ends_on')
                    ->label(__('leave.fields.ends_on'))
                    ->native(false)
                    ->displayFormat('Y-m-d')
                    ->closeOnDateSelection()
                    ->live()
                    ->hidden(fn (Get $get): bool => (bool) $get('is_exit_and_return'))
                    ->required(fn (Get $get): bool => ! (bool) $get('is_exit_and_return'))
                    ->afterOrEqual('starts_on')
                    ->rules([
                        fn (Get $get): Closure => static function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $span = self::dayCount($get('starts_on'), $value);

                            if ($span !== null && $span > LeaveRequest::MAX_DAYS) {
                                $fail(__('leave.validation.too_long', ['days' => LeaveRequest::MAX_DAYS]));
                            }
                        },
                    ])
                    ->validationMessages([
                        'required' => __('leave.validation.ends_required'),
                        'after_or_equal' => __('leave.validation.ends_before_start'),
                    ]),

                Placeholder::make('days')
                    ->label(__('leave.fields.days'))
                    ->content(function (Get $get): string {
                        $span = self::dayCount(
                            $get('starts_on'),
                            (bool) $get('is_exit_and_return') ? $get('starts_on') : $get('ends_on'),
                        );

                        return $span === null || $span < 1
                            ? __('leave.placeholders.days')
                            : trans_choice('leave.units.days', $span);
                    }),

                Textarea::make('reason')
                    ->label(__('leave.fields.reason'))
                    ->placeholder(__('leave.placeholders.reason'))
                    ->helperText(__('leave.helpers.reason'))
                    ->rows(3)
                    ->required()
                    ->minLength(self::REASON_MIN_LENGTH)
                    ->maxLength(self::REASON_MAX_LENGTH)
                    ->validationMessages([
                        'required' => __('leave.validation.reason_required'),
                        'min' => __('leave.validation.reason_min', ['min' => self::REASON_MIN_LENGTH]),
                        'max' => __('leave.validation.reason_max', ['max' => self::REASON_MAX_LENGTH]),
                    ]),

                // The state path is the column name so the draft reads it
                // without a translation step. The browser hands over a file
                // and never a path: Filament writes the stored path itself,
                // and refuses a submitted path it did not write.
                FileUpload::make('attachment_path')
                    ->label(__('leave.fields.attachment'))
                    ->helperText(__('leave.helpers.attachment', ['size' => self::megabytes()]))
                    ->disk(LeaveAttachmentStore::DISK)
                    ->visibility('private')
                    ->acceptedFileTypes(LeaveAttachmentStore::ACCEPTED_MIME_TYPES)
                    ->maxSize(self::kilobytes())
                    // The original name travels beside the path so the
                    // administration is shown the file the employee sent
                    // rather than the identifier this application gave it.
                    ->storeFileNamesIn('attachment_name')
                    ->validationMessages([
                        'mimetypes' => __('leave.validation.attachment_type'),
                        'max' => __('leave.validation.attachment_size', ['size' => self::megabytes()]),
                    ]),
            ]);
    }

    /**
     * The earliest start a request may name.
     *
     * Thirty days back, deliberately: a sick day is reported after the fact,
     * and that is the normal case rather than the exception.
     */
    public static function earliestStart(CarbonImmutable $today): CarbonImmutable
    {
        return $today->subDays(30);
    }

    public static function latestStart(CarbonImmutable $today): CarbonImmutable
    {
        return $today->addYear();
    }

    /**
     * The store's ceiling in the unit Laravel's `max` rule counts files in.
     * The number itself lives with the code that keeps the files, so the
     * form and the download can never disagree about how big a file may be.
     */
    private static function kilobytes(): int
    {
        return intdiv(LeaveAttachmentStore::MAX_BYTES, 1024);
    }

    private static function megabytes(): int
    {
        return intdiv(LeaveAttachmentStore::MAX_BYTES, 1024 * 1024);
    }

    /**
     * Calendar days between two form values, both ends included, or null
     * while either date is still unanswered.
     */
    private static function dayCount(mixed $from, mixed $until): ?int
    {
        if (! is_string($from) || ! is_string($until) || trim($from) === '' || trim($until) === '') {
            return null;
        }

        $timezone = config('app.timezone');

        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($until, $timezone)->startOfDay();

        if ($end->lessThan($start)) {
            return null;
        }

        return (int) $start->diffInDays($end) + 1;
    }
}
