<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances\Schemas;

use App\Enums\RequestStatus;
use App\Filament\Resources\AttendanceCorrections\AttendanceCorrectionResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Support\Geo\Coordinates;
use App\Support\Geo\GoogleMapsLink;
use App\Support\Geo\Meters;
use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * One record in full: the two moments, where the device said it was, how
 * sure it was, and how far that is from the company - everything the
 * workflow saw when it decided.
 *
 * The moment and the position are monospaced, because two coordinates
 * seven decimals long are compared digit by digit or not at all, and both
 * hold nothing but digits. The distance and the accuracy are not: their
 * unit is an Arabic letter, and a monospaced stack has none to draw it
 * with. The two sections carry the panel's arrival and departure glyphs,
 * so a reader who opened the modal to check a check-out knows which half
 * to read before reading a word of it.
 *
 * A corrected record is the one case where this modal could mislead. The
 * moment at the top of a section may have been moved by an approved
 * correction while the coordinates, the accuracy and the distance under it
 * still describe the instant the device measured - which is a different
 * instant. Nothing here rewrites them, because inventing a position for a
 * time nothing measured is the one thing this product must never do; so
 * each distance says out loud which moment it describes, the map button
 * disappears where there is no position at all, and a section at the foot
 * of the modal prints what the device recorded, why it was changed and who
 * approved the change.
 */
final class AttendanceInfolist
{
    /**
     * A coordinate pair is two runs of digits joined by ", ", and every
     * character between them is neutral, so the bidirectional algorithm
     * reorders the pair in the Arabic interface and prints the longitude
     * first. An administrator checking where a device stood would read
     * 46.6753000 as the latitude - a point in the Indian Ocean rather than
     * the office. The attribute pins the reading direction of the value
     * only; the theme puts its alignment back.
     *
     * @var array<string, string>
     */
    private const LTR_FIGURE = ['dir' => 'ltr'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('attendance.sections.check_in'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    // The two halves sit side by side, so a session with no
                    // check-out yet would leave the arrival stranded in one
                    // column of a modal twice its width. On its own it takes
                    // the whole width instead; below the breakpoint where
                    // the grid collapses this changes nothing.
                    ->columnSpan(fn (Attendance $record): int|string => $record->isOpen() ? 'full' : 1)
                    ->schema([
                        TextEntry::make('check_in_at')
                            ->label(__('attendance.fields.check_in_at'))
                            ->dateTime('Y-m-d H:i')
                            ->fontFamily(FontFamily::Mono)
                            // The one sentence that keeps a corrected
                            // moment from reading as a verified one, on the
                            // screen where the evidence is laid out.
                            ->helperText(fn (Attendance $record): ?string => $record->isCheckInCorrected()
                                ? __('attendance.helpers.corrected_not_verified')
                                : null),

                        TextEntry::make('check_in_location')
                            ->label(__('attendance.fields.check_in_location'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->state(fn (Attendance $record): ?string => self::position(
                                $record->check_in_latitude,
                                $record->check_in_longitude,
                            )),

                        TextEntry::make('check_in_accuracy')
                            ->label(__('attendance.fields.check_in_accuracy'))
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->formatStateUsing(fn (string $state): string => self::accuracy($state)),

                        // The sentence qualifies a distance, so it appears
                        // only where there is one: on a moment the device
                        // never recorded there is nothing for it to
                        // describe, and the placeholder above has already
                        // said so.
                        TextEntry::make('check_in_distance_from_company')
                            ->label(__('attendance.fields.check_in_distance'))
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->formatStateUsing(fn (string $state): string => self::meters($state))
                            ->helperText(fn (Attendance $record): ?string => $record->isCheckInCorrected() && $record->hasDeviceCheckIn()
                                ? __('attendance.helpers.distance_describes_device_reading')
                                : null),

                        Actions::make([
                            self::openMap('openCheckInMap', fn (Attendance $record): ?Coordinates => $record->checkInCoordinates()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('attendance.sections.check_out'))
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->hidden(fn (Attendance $record): bool => $record->isOpen())
                    ->schema([
                        TextEntry::make('check_out_at')
                            ->label(__('attendance.fields.check_out_at'))
                            ->dateTime('Y-m-d H:i')
                            ->fontFamily(FontFamily::Mono)
                            ->helperText(fn (Attendance $record): ?string => $record->isCheckOutCorrected()
                                ? __('attendance.helpers.corrected_not_verified')
                                : null),

                        TextEntry::make('check_out_location')
                            ->label(__('attendance.fields.check_out_location'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->state(fn (Attendance $record): ?string => self::position(
                                $record->check_out_latitude,
                                $record->check_out_longitude,
                            )),

                        TextEntry::make('check_out_accuracy')
                            ->label(__('attendance.fields.check_out_accuracy'))
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->formatStateUsing(fn (string $state): string => self::accuracy($state)),

                        TextEntry::make('check_out_distance_from_company')
                            ->label(__('attendance.fields.check_out_distance'))
                            ->placeholder(__('attendance.placeholders.no_device_record'))
                            ->formatStateUsing(fn (string $state): string => self::meters($state))
                            ->helperText(fn (Attendance $record): ?string => $record->isCheckOutCorrected() && $record->hasDeviceCheckOut()
                                ? __('attendance.helpers.distance_describes_device_reading')
                                : null),

                        Actions::make([
                            self::openMap('openCheckOutMap', fn (Attendance $record): ?Coordinates => $record->checkOutCoordinates()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                self::correction(),
            ]);
    }

    /**
     * What the device recorded before an approved correction moved it.
     *
     * Absent from an untouched record, which is nearly all of them. Present,
     * it answers the three questions the two sections above raise and cannot
     * answer themselves: what was there before, why it was changed, and who
     * agreed to change it. The button beside them opens the requests that
     * did it, because the reason on this screen is a summary and the
     * employee's own account of it is on the request.
     */
    private static function correction(): Section
    {
        return Section::make(__('attendance.sections.correction'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->hidden(fn (Attendance $record): bool => ! $record->isCorrected())
            ->columnSpanFull()
            ->schema([
                // deviceCheckInAt() and not the archive column: an
                // uncorrected half has nothing archived because the moment
                // it holds IS the device's, and printing "the device
                // recorded nothing" over it would be false.
                TextEntry::make('original_check_in_at')
                    ->label(__('attendance.fields.original_check_in_at'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(__('attendance.placeholders.no_device_record'))
                    ->state(fn (Attendance $record): ?string => $record->deviceCheckInAt()?->format('Y-m-d H:i')),

                TextEntry::make('original_check_out_at')
                    ->label(__('attendance.fields.original_check_out_at'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder(__('attendance.placeholders.no_device_record'))
                    ->state(fn (Attendance $record): ?string => $record->deviceCheckOutAt()?->format('Y-m-d H:i')),

                TextEntry::make('correction_reason')
                    ->label(__('attendance.fields.correction_reason'))
                    ->state(fn (Attendance $record): ?string => self::reasons($record)),

                TextEntry::make('corrected_by')
                    ->label(__('attendance.fields.corrected_by'))
                    ->state(fn (Attendance $record): ?string => self::approvers($record)),

                Actions::make([
                    Action::make('openCorrection')
                        ->label(__('attendance.admin_actions.open_correction'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->color('gray')
                        ->outlined()
                        ->url(fn (Attendance $record): string => AttendanceCorrectionResource::getUrl('index', [
                            'filters' => [
                                'status' => ['value' => RequestStatus::Approved->value],
                                'user_id' => ['value' => $record->user_id],
                                'date_range' => [
                                    'from' => $record->attendance_date->toDateString(),
                                    'until' => $record->attendance_date->toDateString(),
                                ],
                            ],
                        ])),
                ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * @param  Closure(Attendance): ?Coordinates  $coordinates
     */
    private static function openMap(string $name, Closure $coordinates): Action
    {
        // An outlined button rather than a text link: it is the one thing
        // in the modal that does something, and a link set in the body
        // colour beneath four rows of readings is easy to read past.
        //
        // Absent altogether where the moment came from a correction and no
        // device ever reported a position: a button that links nowhere is
        // worse than no button, because it teaches the reader that the map
        // sometimes fails rather than that this moment was never measured.
        return Action::make($name)
            ->label(__('attendance.admin_actions.open_map'))
            ->icon(Heroicon::OutlinedMap)
            ->color('gray')
            ->outlined()
            ->hidden(fn (Attendance $record): bool => ! $coordinates($record) instanceof Coordinates)
            ->url(function (Attendance $record) use ($coordinates): ?string {
                $position = $coordinates($record);

                return $position instanceof Coordinates ? GoogleMapsLink::to($position) : null;
            })
            ->openUrlInNewTab();
    }

    /**
     * The stored decimals verbatim - seven places, the precision the audit
     * keeps - rather than a float that would drop trailing zeros. Null
     * where the device reported no position at all, so the entry falls to
     * its placeholder instead of printing a pair of dashes.
     */
    private static function position(?string $latitude, ?string $longitude): ?string
    {
        if ($latitude === null || $longitude === null) {
            return null;
        }

        return $latitude.', '.$longitude;
    }

    /**
     * Why the record was amended, from the requests that amended it.
     *
     * Both halves may have been corrected by different requests for
     * different reasons, so the reasons are gathered and de-duplicated
     * rather than one of them being picked.
     */
    private static function reasons(Attendance $record): ?string
    {
        $reasons = array_map(
            static fn (AttendanceCorrection $correction): string => $correction->reason->label(),
            self::corrections($record),
        );

        return self::join($reasons);
    }

    private static function approvers(Attendance $record): ?string
    {
        $names = [];

        foreach (self::corrections($record) as $correction) {
            $name = $correction->decidedBy?->name;

            if ($name !== null) {
                $names[] = $name;
            }
        }

        return self::join($names);
    }

    /**
     * The approved requests that amended this record, at most two.
     *
     * @return array<int, AttendanceCorrection>
     */
    private static function corrections(Attendance $record): array
    {
        $corrections = [];

        foreach ([$record->checkInCorrection, $record->checkOutCorrection] as $correction) {
            if ($correction instanceof AttendanceCorrection) {
                $corrections[$correction->id] = $correction;
            }
        }

        return array_values($corrections);
    }

    /**
     * @param  array<int, string>  $values
     */
    private static function join(array $values): ?string
    {
        $unique = array_unique($values);

        return $unique === [] ? null : implode(' • ', $unique);
    }

    private static function accuracy(string $meters): string
    {
        return __('attendance.units.accuracy', ['value' => Meters::format($meters)]);
    }

    private static function meters(string $meters): string
    {
        return __('attendance.units.meters', ['value' => Meters::format($meters)]);
    }
}
