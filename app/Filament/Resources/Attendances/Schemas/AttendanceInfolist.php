<?php

declare(strict_types=1);

namespace App\Filament\Resources\Attendances\Schemas;

use App\Models\Attendance;
use App\Support\Geo\Coordinates;
use App\Support\Geo\GoogleMapsLink;
use App\Support\Geo\Meters;
use Closure;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * One record in full: the two moments, where the device said it was, how
 * sure it was, and how far that is from the company - everything the
 * workflow saw when it decided.
 */
final class AttendanceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('attendance.sections.check_in'))
                    ->schema([
                        TextEntry::make('check_in_at')
                            ->label(__('attendance.fields.check_in_at'))
                            ->dateTime('Y-m-d H:i'),

                        TextEntry::make('check_in_location')
                            ->label(__('attendance.fields.check_in_location'))
                            ->state(fn (Attendance $record): string => self::position(
                                $record->check_in_latitude,
                                $record->check_in_longitude,
                            )),

                        TextEntry::make('check_in_accuracy')
                            ->label(__('attendance.fields.check_in_accuracy'))
                            ->formatStateUsing(fn (string $state): string => self::accuracy($state)),

                        TextEntry::make('check_in_distance_from_company')
                            ->label(__('attendance.fields.check_in_distance'))
                            ->formatStateUsing(fn (string $state): string => self::meters($state)),

                        Actions::make([
                            self::openMap('openCheckInMap', fn (Attendance $record): Coordinates => $record->checkInCoordinates()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make(__('attendance.sections.check_out'))
                    ->hidden(fn (Attendance $record): bool => $record->isOpen())
                    ->schema([
                        TextEntry::make('check_out_at')
                            ->label(__('attendance.fields.check_out_at'))
                            ->dateTime('Y-m-d H:i'),

                        TextEntry::make('check_out_location')
                            ->label(__('attendance.fields.check_out_location'))
                            ->state(fn (Attendance $record): string => self::position(
                                $record->check_out_latitude,
                                $record->check_out_longitude,
                            )),

                        TextEntry::make('check_out_accuracy')
                            ->label(__('attendance.fields.check_out_accuracy'))
                            ->formatStateUsing(fn (string $state): string => self::accuracy($state)),

                        TextEntry::make('check_out_distance_from_company')
                            ->label(__('attendance.fields.check_out_distance'))
                            ->formatStateUsing(fn (string $state): string => self::meters($state)),

                        Actions::make([
                            self::openMap('openCheckOutMap', fn (Attendance $record): ?Coordinates => $record->checkOutCoordinates()),
                        ])
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * @param  Closure(Attendance): ?Coordinates  $coordinates
     */
    private static function openMap(string $name, Closure $coordinates): Action
    {
        return Action::make($name)
            ->label(__('attendance.admin_actions.open_map'))
            ->icon(Heroicon::OutlinedMap)
            ->link()
            ->url(function (Attendance $record) use ($coordinates): ?string {
                $position = $coordinates($record);

                return $position instanceof Coordinates ? GoogleMapsLink::to($position) : null;
            })
            ->openUrlInNewTab();
    }

    /**
     * The stored decimals verbatim - seven places, the precision the audit
     * keeps - rather than a float that would drop trailing zeros.
     */
    private static function position(?string $latitude, ?string $longitude): string
    {
        return ($latitude ?? '—').', '.($longitude ?? '—');
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
