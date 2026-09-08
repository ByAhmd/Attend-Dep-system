<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceCorrections\Schemas;

use App\Enums\CorrectionReason;
use App\Enums\RequestStatus;
use App\Filament\Resources\Attendances\AttendanceResource;
use App\Models\Attendance;
use App\Models\AttendanceCorrection;
use App\Support\Geo\Meters;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * One correction request in full, and the two blocks the decision is
 * actually made on.
 *
 * Approving a correction is the only act in this system that changes an
 * attendance row, so the screen that asks for it has one job: put what the
 * device recorded and what the employee is asking for beside each other,
 * in the same units, at the same size, so a reader answering twelve of
 * these on a Sunday morning cannot mistake one for the other. Neither
 * column is emphasised over the other - the facts do not change because
 * the answer is going to be no.
 *
 * The two blocks are built here rather than in each action, and the record
 * modal and both decision modals render the very same components. An
 * approval taken from the row and an approval taken from the record modal
 * therefore cannot be looking at different numbers.
 *
 * "What the device recorded" prints the moment that is stored now, because
 * that is the moment an approval overwrites. Where that moment was itself
 * put there by an earlier correction the helper says so in the device's own
 * words - "the device recorded 08:00" - so the column's heading keeps its
 * promise on a row that has already been amended once.
 */
final class AttendanceCorrectionInfolist
{
    /**
     * A clock time is nothing but digits and reads left to right in both
     * languages; the bidirectional algorithm would otherwise reorder the
     * arrow between two of them.
     *
     * @var array<string, string>
     */
    private const LTR_FIGURE = ['dir' => 'ltr'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                self::request(),
                self::comparison(),
                self::decision(),
            ]);
    }

    /**
     * Who asked, about which day, why, and what they wrote.
     *
     * Rendered above the comparison in the record modal and in both
     * decision modals: the reason and the employee's own sentence are half
     * of what an approver is weighing, and a modal that showed only two
     * columns of times would be asking for a judgement with the argument
     * left out.
     */
    public static function request(): Section
    {
        return Section::make(__('corrections.sections.request'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                TextEntry::make('user.name')
                    ->label(__('corrections.fields.employee')),

                TextEntry::make('attendance_date')
                    ->label(__('corrections.fields.attendance_date'))
                    ->date('Y-m-d')
                    ->fontFamily(FontFamily::Mono),

                TextEntry::make('reason')
                    ->label(__('corrections.fields.reason'))
                    ->formatStateUsing(fn (CorrectionReason $state): string => $state->label())
                    ->columnSpanFull(),

                TextEntry::make('note')
                    ->label(__('corrections.fields.note'))
                    ->placeholder(__('corrections.placeholders.no_note'))
                    ->columnSpanFull(),

                TextEntry::make('submitted_at')
                    ->label(__('corrections.fields.submitted_at'))
                    ->dateTime('Y-m-d H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray'),

                // Why this pending request offers no buttons. Without it the
                // only administrator on a small deployment meets the rule as
                // a missing control and reads it as a bug; with it they meet
                // it as a sentence and know the remedy.
                Text::make(__('corrections.helpers.own_request'))
                    ->color('gray')
                    ->visible(fn (AttendanceCorrection $record): bool => $record->status->isPending()
                        && (int) $record->user_id === (int) Auth::id())
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The device's record and the employee's claim, side by side.
     *
     * Side by side and not one above the other: the comparison is the whole
     * decision, and a reader scrolling from one to the other is comparing
     * a number against a memory. Below the sm breakpoint the grid collapses
     * and the two stack, which on a phone is the only honest arrangement -
     * but the labels are complete on both sides, so neither block depends
     * on the one beside it to be read.
     */
    public static function comparison(): Grid
    {
        return Grid::make(['default' => 1, 'sm' => 2])
            ->schema([
                Section::make(__('corrections.sections.recorded'))
                    ->icon(Heroicon::OutlinedDevicePhoneMobile)
                    ->schema([
                        TextEntry::make('recorded_check_in')
                            ->label(__('corrections.fields.recorded_check_in'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('corrections.placeholders.no_device_record'))
                            ->state(fn (AttendanceCorrection $record): ?string => self::session($record)?->check_in_at?->format('H:i'))
                            ->helperText(fn (AttendanceCorrection $record): ?string => self::deviceMoment(
                                self::session($record),
                                checkIn: true,
                            )),

                        TextEntry::make('recorded_check_out')
                            ->label(__('corrections.fields.recorded_check_out'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('corrections.placeholders.no_device_record'))
                            ->state(fn (AttendanceCorrection $record): ?string => self::session($record)?->check_out_at?->format('H:i'))
                            ->helperText(fn (AttendanceCorrection $record): ?string => self::deviceMoment(
                                self::session($record),
                                checkIn: false,
                            )),

                        // The distance is what made the recorded moment
                        // evidence rather than a claim, so it belongs on
                        // this side of the comparison and nowhere near the
                        // other. Beside a moment an earlier correction
                        // already moved it describes the reading, not the
                        // time above it, and says so.
                        TextEntry::make('recorded_distance')
                            ->label(__('corrections.fields.recorded_distance'))
                            ->placeholder(__('corrections.placeholders.no_device_record'))
                            ->state(fn (AttendanceCorrection $record): ?string => self::distance(self::session($record)))
                            ->helperText(fn (AttendanceCorrection $record): ?string => self::session($record)?->isCheckInCorrected() === true
                                ? __('attendance.helpers.distance_describes_device_reading')
                                : null),
                    ]),

                Section::make(__('corrections.sections.requested'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->schema([
                        TextEntry::make('requested_check_in')
                            ->label(__('corrections.fields.requested_check_in'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('corrections.placeholders.unchanged'))
                            ->state(fn (AttendanceCorrection $record): ?string => $record->requestedCheckInAt()?->format('H:i')),

                        TextEntry::make('requested_check_out')
                            ->label(__('corrections.fields.requested_check_out'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
                            ->placeholder(__('corrections.placeholders.unchanged'))
                            ->state(fn (AttendanceCorrection $record): ?string => $record->requestedCheckOutAt()?->format('H:i')),
                    ]),
            ]);
    }

    /**
     * What was decided, by whom, and what they said about it.
     *
     * Absent while the request is pending: a decision section printing
     * three placeholders would read as a decision that went missing.
     */
    private static function decision(): Section
    {
        return Section::make(__('corrections.sections.decision'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->hidden(fn (AttendanceCorrection $record): bool => $record->status->isPending())
            ->schema([
                TextEntry::make('status')
                    ->label(__('corrections.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                TextEntry::make('decidedBy.name')
                    ->label(__('corrections.fields.decided_by')),

                TextEntry::make('decided_at')
                    ->label(__('corrections.fields.decided_at'))
                    ->dateTime('Y-m-d H:i')
                    ->fontFamily(FontFamily::Mono),

                TextEntry::make('decision_note')
                    ->label(__('corrections.fields.decision_note'))
                    ->placeholder(__('corrections.placeholders.no_note'))
                    ->columnSpanFull(),

                // The day this request was about, in the list that holds
                // every session of it. An approved correction is only half
                // read here; the other half is the row it amended.
                Actions::make([
                    Action::make('openAttendance')
                        ->label(__('corrections.actions.open_attendance'))
                        ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                        ->color('gray')
                        ->outlined()
                        ->url(fn (AttendanceCorrection $record): string => AttendanceResource::getUrl('index', [
                            'filters' => [
                                'user_id' => ['value' => $record->user_id],
                                'date' => ['date' => $record->attendance_date->toDateString()],
                            ],
                        ])),
                ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The session the request names, or null when the day held none and the
     * correction is proposing to create one.
     */
    private static function session(AttendanceCorrection $record): ?Attendance
    {
        return $record->attendance;
    }

    /**
     * What the device actually recorded for a moment an earlier correction
     * has already moved, or null when the stored moment IS the device's.
     */
    private static function deviceMoment(?Attendance $session, bool $checkIn): ?string
    {
        if (! $session instanceof Attendance) {
            return null;
        }

        if (! ($checkIn ? $session->isCheckInCorrected() : $session->isCheckOutCorrected())) {
            return null;
        }

        $moment = $checkIn ? $session->deviceCheckInAt() : $session->deviceCheckOutAt();

        return $moment === null
            ? __('attendance.placeholders.no_device_record')
            : __('attendance.badges.corrected_from', ['time' => $moment->format('H:i')]);
    }

    /**
     * How far from the company the device stood when it recorded the
     * check-in, in whole metres - the unit the rule is written in.
     */
    private static function distance(?Attendance $session): ?string
    {
        $meters = $session?->check_in_distance_from_company;

        return $meters === null
            ? null
            : __('attendance.units.meters', ['value' => Meters::format($meters)]);
    }
}
