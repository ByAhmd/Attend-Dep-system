<?php

declare(strict_types=1);

namespace App\Filament\Resources\LeaveRequests\Schemas;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Services\Leave\LeaveConflicts;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;

/**
 * One leave request in full, and what it runs into.
 *
 * Approving leave changes no attendance row - it records what was agreed
 * and nothing else - so the decision here is a judgement about the person
 * and their days, not an amendment to a record. The screen is arranged
 * accordingly: the request on one side, and on the other the two things
 * that ought to give an approver pause.
 *
 * The two are not the same kind of fact, and the interface must not blur
 * them. **Attendance recorded inside the range is a warning**: half a day
 * worked before going home is ordinary, and the system does not overrule
 * the person who was actually there. **Approved leave already covering
 * those days is a refusal**, and it is the workflow that refuses it, under
 * a lock, at the moment of the decision - what is printed here is only
 * what was true when the modal was opened.
 *
 * Both are computed once per record from LeaveConflicts and never from a
 * table column: two queries when a modal opens is nothing, and the same two
 * queries per row of a list would be the most expensive screen in the
 * panel.
 */
final class LeaveRequestInfolist
{
    /**
     * A date range joined by an arrow is digits and neutral characters, so
     * the bidirectional algorithm would print the end date first in Arabic.
     *
     * @var array<string, string>
     */
    private const LTR_FIGURE = ['dir' => 'ltr'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])
                    ->schema([
                        self::request(),
                        self::conflicts(),
                    ]),

                self::decision(),
            ]);
    }

    /**
     * What was asked for, in the employee's own words.
     *
     * The reason is the argument, so it takes the full width of the section
     * rather than sharing a row with a date: an approver who cannot read
     * the sentence without unwrapping it will decide without it.
     */
    public static function request(): Section
    {
        return Section::make(__('leave.sections.request'))
            ->icon(Heroicon::OutlinedDocumentText)
            ->schema([
                TextEntry::make('user.name')
                    ->label(__('leave.fields.employee')),

                TextEntry::make('type')
                    ->label(__('leave.fields.type'))
                    ->formatStateUsing(fn (LeaveType $state): string => $state->label()),

                TextEntry::make('period')
                    ->label(__('leave.fields.period'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(self::LTR_FIGURE)
                    ->state(fn (LeaveRequest $record): string => self::period($record)),

                TextEntry::make('days')
                    ->label(__('leave.fields.days'))
                    ->state(fn (LeaveRequest $record): string => trans_choice('leave.units.days', $record->dayCount())),

                // A tick or a grey cross rather than a word: the flag turns
                // a one-day request from a day off into a few hours away,
                // and it has to be legible at a glance beside the dates it
                // changes the meaning of.
                IconEntry::make('is_exit_and_return')
                    ->label(__('leave.fields.is_exit_and_return'))
                    ->boolean()
                    ->falseColor('gray'),

                TextEntry::make('submitted_at')
                    ->label(__('leave.fields.submitted_at'))
                    ->dateTime('Y-m-d H:i')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray'),

                TextEntry::make('reason')
                    ->label(__('leave.fields.reason'))
                    ->columnSpanFull(),

                // The file itself is never linked by path. This points at
                // the named route, which asks this request's own policy
                // before it opens the private disk, so the link is a
                // question and never a capability - a URL copied out of
                // this modal is refused to anybody the policy refuses.
                TextEntry::make('attachment_name')
                    ->label(__('leave.fields.attachment'))
                    ->placeholder(__('leave.placeholders.no_attachment'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->url(fn (LeaveRequest $record): ?string => $record->attachment_path === null
                        ? null
                        : route('leave-attachments.show', $record))
                    ->openUrlInNewTab()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * The two facts an approver should meet before saying yes.
     *
     * Both print their absence rather than disappearing: a conflicts block
     * that were empty when there is nothing to report would leave a reader
     * unable to tell "nothing overlaps" from "nobody checked".
     */
    public static function conflicts(): Section
    {
        return Section::make(__('leave.sections.conflicts'))
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema([
                TextEntry::make('attended_days')
                    ->label(__('leave.fields.attended_days'))
                    ->placeholder(__('leave.placeholders.no_overlap'))
                    ->state(fn (LeaveRequest $record): ?string => self::attendedDays($record)),

                TextEntry::make('overlapping_leave')
                    ->label(__('leave.fields.overlapping_leave'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(self::LTR_FIGURE)
                    ->placeholder(__('leave.placeholders.no_overlap'))
                    ->state(fn (LeaveRequest $record): ?string => app(LeaveConflicts::class)->overlapSummary($record)),
            ])
            ->columns(1);
    }

    private static function decision(): Section
    {
        return Section::make(__('leave.sections.decision'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->hidden(fn (LeaveRequest $record): bool => $record->status->isPending())
            ->schema([
                TextEntry::make('status')
                    ->label(__('leave.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                TextEntry::make('decidedBy.name')
                    ->label(__('leave.fields.decided_by')),

                TextEntry::make('decided_at')
                    ->label(__('leave.fields.decided_at'))
                    ->dateTime('Y-m-d H:i')
                    ->fontFamily(FontFamily::Mono),

                TextEntry::make('decision_note')
                    ->label(__('leave.fields.decision_note'))
                    ->placeholder(__('leave.placeholders.no_note'))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    /**
     * Days inside the range on which the employee actually recorded a
     * session, or null when there were none.
     *
     * Null rather than "0", so the entry falls to its placeholder: a
     * quantity of nothing, printed as a figure, reads as a finding.
     */
    private static function attendedDays(LeaveRequest $record): ?string
    {
        $days = app(LeaveConflicts::class)->attendedDays($record);

        return $days === 0 ? null : trans_choice('leave.units.days', $days);
    }

    private static function period(LeaveRequest $record): string
    {
        return $record->starts_on->format('Y-m-d').' → '.$record->ends_on->format('Y-m-d');
    }
}
