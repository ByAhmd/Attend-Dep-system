<?php

declare(strict_types=1);

namespace App\Filament\Resources\JobTitles\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Create and rename a job title.
 *
 * Two names, both required, because this is the one string in the product
 * a company types itself and it is printed under a person's name on a
 * screen read in two languages. One name would mean an Arabic word sitting
 * inside an English sentence, or the reverse, on every account that holds
 * it - so the form asks for both once rather than letting the second be
 * added later, when the title is already on twenty accounts.
 *
 * Each name is unique in its own right and says so in its own words: a
 * database error naming an index is not an answer to "why can I not save
 * this?", and the two messages differ so the reader knows which of the two
 * boxes to look at.
 *
 * The retirement toggle is on the same form rather than only in the row
 * menu, because the person renaming a title is often the person who has
 * just decided to stop using it.
 */
final class JobTitleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('job_titles.sections.details'))
                    ->icon(Heroicon::OutlinedTag)
                    ->description(__('job_titles.helpers.name'))
                    ->aside()
                    ->schema([
                        TextInput::make('name_ar')
                            ->label(__('job_titles.fields.name_ar'))
                            ->placeholder(__('job_titles.placeholders.name_ar'))
                            ->required()
                            ->minLength(2)
                            ->maxLength(60)
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => __('job_titles.validation.name_ar_unique'),
                            ]),

                        TextInput::make('name_en')
                            ->label(__('job_titles.fields.name_en'))
                            ->placeholder(__('job_titles.placeholders.name_en'))
                            // The English name is Latin script on a page whose
                            // paragraph direction is right to left, so the
                            // field is isolated: without it the caret starts
                            // at the wrong end of the box.
                            ->extraInputAttributes(['dir' => 'ltr'])
                            ->required()
                            ->minLength(2)
                            ->maxLength(60)
                            ->unique(ignoreRecord: true)
                            ->validationMessages([
                                'unique' => __('job_titles.validation.name_en_unique'),
                            ]),

                        Toggle::make('is_active')
                            ->label(__('job_titles.fields.status'))
                            ->default(true)
                            ->helperText(__('job_titles.helpers.is_active')),
                    ])
                    ->columns(1),
            ])
            ->columns(1);
    }
}
