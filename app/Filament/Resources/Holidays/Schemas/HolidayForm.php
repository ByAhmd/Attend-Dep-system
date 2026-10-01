<?php

declare(strict_types=1);

namespace App\Filament\Resources\Holidays\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * One holiday: its two names and the inclusive range it covers.
 *
 * Both names are required for the same reason a job title's are - the word
 * is printed to readers in two languages, and a holiday named only in one
 * would be the only string in the product that ignores who is reading.
 * The range is validated here the way the table enforces it: the end on
 * or after the start, a single day being the two equal.
 */
final class HolidayForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name_ar')
                    ->label(__('holidays.fields.name_ar'))
                    ->required()
                    ->maxLength(100)
                    ->validationMessages([
                        'required' => __('holidays.validation.name_required'),
                        'max' => __('holidays.validation.name_length'),
                    ]),

                TextInput::make('name_en')
                    ->label(__('holidays.fields.name_en'))
                    ->required()
                    ->maxLength(100)
                    ->validationMessages([
                        'required' => __('holidays.validation.name_required'),
                        'max' => __('holidays.validation.name_length'),
                    ]),

                DatePicker::make('starts_on')
                    ->label(__('holidays.fields.starts_on'))
                    ->required()
                    ->validationMessages([
                        'required' => __('holidays.validation.date_required'),
                    ]),

                DatePicker::make('ends_on')
                    ->label(__('holidays.fields.ends_on'))
                    ->helperText(__('holidays.helpers.single_day'))
                    ->required()
                    ->afterOrEqual('starts_on')
                    ->validationMessages([
                        'required' => __('holidays.validation.date_required'),
                        'after_or_equal' => __('holidays.validation.range_ordered'),
                    ]),
            ])
            ->columns(2);
    }
}
