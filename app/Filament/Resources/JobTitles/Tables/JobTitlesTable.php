<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Tables;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\JobTitles\Actions\DeleteJobTitleAction;
use App\Filament\Resources\JobTitles\Actions\ToggleJobTitleAction;
use App\Models\JobTitle;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The list of job titles.
 *
 * Both names are on every row, the Arabic above and the English beneath,
 * because a title exists precisely to be read in two languages and a list
 * that showed only the reader's own would hide the half most likely to be
 * missing or wrong. The order is the Arabic name in both languages, so two
 * administrators comparing screens are looking at the same list.
 *
 * The holder count is the column this screen is opened for: it is the
 * difference between a title that can be deleted and one that can only be
 * retired, and it links to the accounts it counted, because the next
 * question is always who they are. It costs one COUNT for the whole page,
 * not one per row - which is what withCount() is doing here and why it is
 * not optional.
 */
final class JobTitlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('users'))
            ->columns([
                TextColumn::make('name_ar')
                    ->label(__('job_titles.fields.name_ar'))
                    ->description(fn (JobTitle $record): string => $record->name_en)
                    ->weight(FontWeight::SemiBold)
                    ->grow()
                    ->searchable(['name_ar', 'name_en'])
                    ->sortable(),

                TextColumn::make('users_count')
                    ->label(__('job_titles.fields.holders'))
                    ->badge()
                    ->icon(Heroicon::OutlinedUsers)
                    // Grey where the answer is none: a zero is not a
                    // warning, it is simply a title nobody has been given
                    // yet, and it is also the only title that can be
                    // deleted.
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    // The filter named here is declared on EmployeesTable;
                    // one that ever stopped existing would be ignored
                    // rather than break the link.
                    ->url(fn (JobTitle $record): ?string => self::holderCount($record) > 0
                        ? EmployeeResource::getUrl('index', [
                            'filters' => ['job_title_id' => ['value' => $record->getKey()]],
                        ])
                        : null)
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label(__('job_titles.fields.status'))
                    ->badge()
                    // Word, glyph and colour together, so the state is
                    // legible in a black and white screenshot and to a
                    // reader who cannot separate the two colours.
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('job_titles.badges.active')
                        : __('job_titles.badges.retired'))
                    ->icon(fn (bool $state): BackedEnum => $state
                        ? Heroicon::OutlinedCheckCircle
                        : Heroicon::OutlinedArchiveBox)
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label(__('job_titles.fields.created_at'))
                    ->dateTime('Y-m-d')
                    ->fontFamily(FontFamily::Mono)
                    ->color('gray')
                    ->toggleable(),
            ])
            ->defaultSort('name_ar')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('job_titles.filters.is_active'))
                    ->placeholder(__('job_titles.filters.any'))
                    ->trueLabel(__('job_titles.filters.active_only'))
                    ->falseLabel(__('job_titles.filters.retired_only')),
            ])
            ->recordActions([
                // One menu rather than three labelled buttons, for the same
                // reason the employee list uses one: the set changes shape
                // with the row, and Arabic labels three abreast do not fit
                // a tablet.
                ActionGroup::make([
                    EditAction::make(),
                    ToggleJobTitleAction::make(),
                    DeleteJobTitleAction::make(),
                ]),
            ])
            ->stackedOnMobile()
            ->emptyStateIcon(Heroicon::OutlinedTag)
            ->emptyStateHeading(__('job_titles.empty.heading'))
            ->emptyStateDescription(__('job_titles.empty.description'));
    }

    /**
     * The holder count modifyQueryUsing() loaded onto this row.
     *
     * Read from the raw attributes rather than as a property: withCount()
     * adds the figure to the row at query time, and the model describes the
     * table's own columns and nothing else.
     */
    private static function holderCount(JobTitle $record): int
    {
        $count = $record->getAttributes()['users_count'] ?? 0;

        return is_numeric($count) ? (int) $count : 0;
    }
}
