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
                            ->fontFamily(FontFamily::Mono),

                        TextEntry::make('check_in_location')
                            ->label(__('attendance.fields.check_in_location'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
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
                    ->icon(Heroicon::OutlinedArrowUpTray)
                    ->hidden(fn (Attendance $record): bool => $record->isOpen())
                    ->schema([
                        TextEntry::make('check_out_at')
                            ->label(__('attendance.fields.check_out_at'))
                            ->dateTime('Y-m-d H:i')
                            ->fontFamily(FontFamily::Mono),

                        TextEntry::make('check_out_location')
                            ->label(__('attendance.fields.check_out_location'))
                            ->fontFamily(FontFamily::Mono)
                            ->extraAttributes(self::LTR_FIGURE)
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
        // An outlined button rather than a text link: it is the one thing
        // in the modal that does something, and a link set in the body
        // colour beneath four rows of readings is easy to read past.
        return Action::make($name)
            ->label(__('attendance.admin_actions.open_map'))
            ->icon(Heroicon::OutlinedMap)
            ->color('gray')
            ->outlined()
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
