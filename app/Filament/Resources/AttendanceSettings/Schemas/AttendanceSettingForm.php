<?php

declare(strict_types=1);

namespace App\Filament\Resources\AttendanceSettings\Schemas;

use App\Models\AttendanceSetting;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Where the company is, and how far from it attendance counts.
 *
 * The coordinate bounds and the radius bounds are the same ones the rest
 * of the system enforces (Coordinates refuses anything outside them, the
 * radius limits come from config), so the form can never store a value the
 * verifier would later choke on.
 */
final class AttendanceSettingForm
{
    public static function configure(Schema $schema): Schema
    {
        $bounds = config('attendance.radius_bounds');
        $minRadius = (int) $bounds['min'];
        $maxRadius = (int) $bounds['max'];

        return $schema
            ->components([
                // Until both coordinates are set, every check-in is refused
                // with "location not configured" - worth saying here, in
                // the one place that can fix it.
                Callout::make(__('settings.helpers.not_configured'))
                    ->warning()
                    ->hidden(fn (?AttendanceSetting $record): bool => $record?->isConfigured() ?? false),

                Section::make(__('settings.sections.location'))
                    ->description(__('settings.helpers.coordinates'))
                    ->schema([
                        TextInput::make('latitude')
                            ->label(__('settings.fields.latitude'))
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

                Section::make(__('settings.sections.radius'))
                    ->schema([
                        TextInput::make('radius_meters')
                            ->label(__('settings.fields.radius_meters'))
                            ->helperText(__('settings.helpers.radius'))
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
                    ->columns(1),
            ])
            ->columns(1);
    }

    private static function radiusMessage(int $min, int $max): string
    {
        return __('settings.validation.radius', ['min' => $min, 'max' => $max]);
    }
}
