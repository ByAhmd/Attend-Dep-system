<?php

declare(strict_types=1);

namespace App\Filament\Employee\Widgets;

use App\Enums\LeaveType;
use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use Filament\Facades\Filament;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Livewire\Attributes\On;

/**
 * The signed-in employee's own leave requests, newest first.
 *
 * Bound to the current account and to nothing else, exactly as the
 * correction table beside it is, and for the same reason: there is no
 * parameter here through which another employee's requests could be
 * reached.
 *
 * The period is one column and not two. An employee reads "14 → 18", not a
 * start beside an end, and the pair printed together is what they check
 * against what they asked for.
 */
#[On('leave-requested')]
final class MyLeaveRequestsWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    /** Rendered with the page, not fetched in a second request after it. */
    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                LeaveRequest::query()
                    ->forUser((int) Filament::auth()->id())
                    ->orderByDesc('submitted_at')
                    ->orderByDesc('id'),
            )
            ->heading(__('leave.employee.heading_table'))
            ->columns([
                TextColumn::make('starts_on')
                    ->label(__('leave.fields.period'))
                    ->state(fn (LeaveRequest $record): string => $record->starts_on->format('Y-m-d')
                        .' → '
                        .$record->ends_on->format('Y-m-d'))
                    ->fontFamily(FontFamily::Mono)
                    ->extraAttributes(['dir' => 'ltr'])
                    ->weight(FontWeight::SemiBold),

                TextColumn::make('type')
                    ->label(__('leave.fields.type'))
                    ->formatStateUsing(fn (LeaveType $state): string => $state->label())
                    ->color('gray')
                    ->wrap(),

                // The unit is an Arabic word, and no monospaced stack has
                // one to draw - so tabular figures, never the mono face.
                TextColumn::make('days')
                    ->label(__('leave.fields.days'))
                    ->state(fn (LeaveRequest $record): string => trans_choice('leave.units.days', $record->dayCount()))
                    ->extraAttributes(['class' => 'fi-numeric']),

                TextColumn::make('status')
                    ->label(__('requests.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (RequestStatus $state): string => $state->label())
                    ->icon(fn (RequestStatus $state): string => $state->icon())
                    ->color(fn (RequestStatus $state): string => $state->color()),

                TextColumn::make('decision_note')
                    ->label(__('requests.fields.decision_note'))
                    ->placeholder(__('requests.placeholders.no_note'))
                    ->wrap(),

                // The document, under the name the employee gave it, linked
                // by route and never by path: the file lives on a private
                // disk above the document root, and the route asks
                // LeaveRequestPolicy who is looking before it sends a byte.
                TextColumn::make('attachment_name')
                    ->label(__('leave.fields.attachment'))
                    ->placeholder(__('leave.placeholders.no_attachment'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->url(fn (LeaveRequest $record): ?string => $record->attachment_path === null
                        ? null
                        : route('leave-attachments.show', $record))
                    ->openUrlInNewTab()
                    ->wrap(),
            ])
            ->stackedOnMobile()
            ->striped()
            ->paginated([5, 25])
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->emptyStateHeading(__('leave.employee.empty_heading'))
            ->emptyStateDescription(__('leave.employee.empty_description'));
    }
}
