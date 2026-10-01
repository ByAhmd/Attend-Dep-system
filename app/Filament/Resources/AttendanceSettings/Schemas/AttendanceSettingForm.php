<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceSettings\Schemas;

use App\Enums\Weekday;
use App\Models\AttendanceSetting;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Where the company is, and how far from it attendance counts.
 *
 * The coordinate bounds and the radius bounds are the same ones the rest
 * of the system enforces (Coordinates refuses anything outside them, the
 * radius limits come from config), so the form can never store a value the
 * verifier would later choke on.
 *
 * This is a screen somebody visits perhaps twice, to paste two numbers
 * copied out of a map, so it is laid out as instructions rather than
 * inputs: each section states its question beside the fields that answer
 * it - a pin for where, a viewfinder for how close, a pencil for how often
 * a mistake may be corrected - which gives each sentence the width to be
 * read instead of the small print under a box. Each coordinate carries a
 * worked example as its placeholder, because the commonest way to get this
 * wrong is to paste the pair the other way round, and 46 where a latitude
 * belongs is only obvious next to a latitude that looks like one.
 *
 * The correction allowance is here rather than in config because it is a
 * business policy the owner changes without an SSH session, and until this
 * field existed the column could only be set with SQL. Zero is a real
 * setting, which is why the helper says out loud what it does: an empty
 * box would look like "unlimited" to a reader who had not been told.
 */
final class AttendanceSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        $bounds = config('attendance.radius_bounds');
        $minRadius = (int) $bounds['min'];
        $maxRadius = (int) $bounds['max'];

        $quotaBounds = config('attendance.correction_quota_bounds');
        $minQuota = (int) $quotaBounds['min'];
        $maxQuota = (int) $quotaBounds['max'];

        $graceBounds = config('attendance.late_grace_bounds');
        $minGrace = (int) $graceBounds['min'];
        $maxGrace = (int) $graceBounds['max'];

        $leaveBounds = config('attendance.annual_leave_bounds');
        $minLeave = (int) $leaveBounds['min'];
        $maxLeave = (int) $leaveBounds['max'];

        return $schema
            ->components([
                // Until both coordinates are set, every check-in is refused
                // with "location not configured" - worth saying here, in
                // the one place that can fix it.
                Callout::make(__('settings.helpers.not_configured'))
                    ->warning()
                    ->hidden(fn (?AttendanceSetting $record): bool => $record?->isConfigured() ?? false),

                Section::make(__('settings.sections.location'))
                    ->icon(Heroicon::OutlinedMapPin)
                    ->description(__('settings.helpers.coordinates'))
                    ->aside()
                    ->schema([
                        TextInput::make('latitude')
                            ->label(__('settings.fields.latitude'))
                            ->placeholder(__('settings.placeholders.latitude'))
                            ->numeric()
                            ->required()
                            ->step(0.0000001)
                            ->rules(['between:-90,90'])
                            ->validationMessages([
                                'required' => __('settings.validation.latitude'),
                                'numeric' => __('settings.validation.latitude'),
                                'between' => __('settings.validation.latitude'),
                            ]),

                        TextInput::make('longitude')
                            ->label(__('settings.fields.longitude'))
                            ->placeholder(__('settings.placeholders.longitude'))
                            ->numeric()
                            ->required()
                            ->step(0.0000001)
                            ->rules(['between:-180,180'])
                            ->validationMessages([
                                'required' => __('settings.validation.longitude'),
                                'numeric' => __('settings.validation.longitude'),
                                'between' => __('settings.validation.longitude'),
                            ]),
                    ])
                    ->columns(2),

                // The sentence that explains the radius moves up here, into
                // the width the aside gives it, rather than being repeated
                // as small print under the box it explains.
                Section::make(__('settings.sections.radius'))
                    ->icon(Heroicon::OutlinedViewfinderCircle)
                    ->description(__('settings.helpers.radius'))
                    ->aside()
                    ->schema([
                        TextInput::make('radius_meters')
                            ->label(__('settings.fields.radius_meters'))
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue($minRadius)
                            ->maxValue($maxRadius)
                            ->default((int) config('attendance.default_radius_meters'))
                            ->suffix(__('settings.fields.radius_suffix'))
                            ->validationMessages([
                                'required' => self::radiusMessage($minRadius, $maxRadius),
                                'numeric' => self::radiusMessage($minRadius, $maxRadius),
                                'integer' => self::radiusMessage($minRadius, $maxRadius),
                                'min' => self::radiusMessage($minRadius, $maxRadius),
                                'max' => self::radiusMessage($minRadius, $maxRadius),
                            ]),
                    ])
                    // Half width, so the metre suffix Filament pins to the
                    // far end of the field lands beside the three digits it
                    // belongs to rather than a hand's width away from them.
                    ->columns(2),

                // The working day is what lateness and early departure are
                // measured against, so its sentence has to say both: an
                // arrival after start-plus-grace is marked late, and a
                // check-out before the end asks for a reason.
                Section::make(__('settings.sections.working_hours'))
                    ->icon(Heroicon::OutlinedClock)
                    ->description(__('settings.helpers.working_hours'))
                    ->aside()
                    ->schema([
                        TimePicker::make('work_starts_at')
                            ->label(__('settings.fields.work_starts_at'))
                            ->seconds(false)
                            ->required()
                            ->validationMessages([
                                'required' => __('settings.validation.work_time'),
                            ]),

                        TimePicker::make('work_ends_at')
                            ->label(__('settings.fields.work_ends_at'))
                            ->seconds(false)
                            ->required()
                            ->after('work_starts_at')
                            ->validationMessages([
                                'required' => __('settings.validation.work_time'),
                                'after' => __('settings.validation.working_day_ordered'),
                            ]),

                        TextInput::make('late_grace_minutes')
                            ->label(__('settings.fields.late_grace_minutes'))
                            ->helperText(__('settings.helpers.late_grace_minutes'))
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue($minGrace)
                            ->maxValue($maxGrace)
                            ->default((int) config('attendance.default_late_grace_minutes'))
                            ->suffix(__('settings.fields.late_grace_suffix'))
                            ->validationMessages([
                                'required' => self::graceMessage($minGrace, $maxGrace),
                                'numeric' => self::graceMessage($minGrace, $maxGrace),
                                'integer' => self::graceMessage($minGrace, $maxGrace),
                                'min' => self::graceMessage($minGrace, $maxGrace),
                                'max' => self::graceMessage($minGrace, $maxGrace),
                            ])
                            ->columnSpanFull(),

                        // The weekend lives beside the hours because the
                        // two answer the same question at different grains:
                        // which hours a working day has, and which days are
                        // not working days at all. At most six may be
                        // ticked - a company with no working day is a
                        // setting no screen could mean - and none is a real
                        // choice for a seven-day operation.
                        CheckboxList::make('weekend_days')
                            ->label(__('settings.fields.weekend_days'))
                            ->helperText(__('settings.helpers.weekend_days'))
                            ->options(Weekday::options())
                            ->columns(2)
                            ->maxItems(6)
                            ->validationMessages([
                                'max' => __('settings.validation.weekend_days_max'),
                            ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('settings.sections.corrections'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->description(__('settings.helpers.correction_requests_per_month'))
                    ->aside()
                    ->schema([
                        TextInput::make('correction_requests_per_month')
                            ->label(__('settings.fields.correction_requests_per_month'))
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue($minQuota)
                            ->maxValue($maxQuota)
                            ->default((int) config('attendance.default_correction_requests_per_month'))
                            ->validationMessages([
                                'required' => __('settings.validation.correction_quota_required'),
                                'numeric' => __('settings.validation.correction_quota_integer'),
                                'integer' => __('settings.validation.correction_quota_integer'),
                                'min' => __('settings.validation.correction_quota_min'),
                                'max' => __('settings.validation.correction_quota_max', ['max' => $maxQuota]),
                            ]),
                    ])
                    // Half width for the same reason as the radius: a box
                    // that holds at most two digits should not be as wide
                    // as the sentence explaining it.
                    ->columns(2),
                Section::make(__('settings.sections.annual_leave'))
                    ->icon(Heroicon::OutlinedCalendarDays)
                    ->description(__('settings.helpers.annual_leave_days'))
                    ->aside()
                    ->schema([
                        TextInput::make('annual_leave_days')
                            ->label(__('settings.fields.annual_leave_days'))
                            ->numeric()
                            ->integer()
                            ->required()
                            ->minValue($minLeave)
                            ->maxValue($maxLeave)
                            ->default((int) config('attendance.default_annual_leave_days'))
                            ->suffix(__('settings.fields.annual_leave_suffix'))
                            ->validationMessages([
                                'required' => self::annualLeaveMessage($minLeave, $maxLeave),
                                'numeric' => self::annualLeaveMessage($minLeave, $maxLeave),
                                'integer' => self::annualLeaveMessage($minLeave, $maxLeave),
                                'min' => self::annualLeaveMessage($minLeave, $maxLeave),
                                'max' => self::annualLeaveMessage($minLeave, $maxLeave),
                            ]),
                    ])
                    // Half width, like every box on this screen that holds
                    // at most three digits.
                    ->columns(2),
            ])
            ->columns(1);
    }

    private static function annualLeaveMessage(int $min, int $max): string
    {
        return __('settings.validation.annual_leave', ['min' => $min, 'max' => $max]);
    }

    private static function radiusMessage(int $min, int $max): string
    {
        return __('settings.validation.radius', ['min' => $min, 'max' => $max]);
    }

    private static function graceMessage(int $min, int $max): string
    {
        return __('settings.validation.late_grace', ['min' => $min, 'max' => $max]);
    }
}
