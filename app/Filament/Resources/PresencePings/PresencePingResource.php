<?php

declare(strict_types=1);

namespace App\Filament\Resources\PresencePings;

use App\Enums\NavigationGroup;
use App\Filament\Resources\PresencePings\Pages\ListPresencePings;
use App\Filament\Resources\PresencePings\Tables\PresencePingsTable;
use App\Models\PresencePing;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Presence pings, read-only.
 *
 * One page, the list. There is no create, edit or delete page and the
 * policy denies every write to everyone: PresencePingRecorder is the only
 * writer, and an observation an administrator could add to or amend
 * afterwards would not be an observation.
 */
final class PresencePingResource extends Resource
{
    protected static ?string $model = PresencePing::class;

    protected static ?string $slug = 'presence-pings';

    // Page headings keep the sentence case of the sidebar label.
    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    // The same glyph filled in, so the entry the reader is standing on
    // is legible as the current one from the shape alone.
    protected static string|BackedEnum|null $activeNavigationIcon = Heroicon::Signal;

    protected static ?int $navigationSort = 3;

    public static function getNavigationGroup(): NavigationGroup
    {
        return NavigationGroup::Attendance;
    }

    public static function getNavigationLabel(): string
    {
        return __('presence.navigation.label');
    }

    public static function getModelLabel(): string
    {
        return __('presence.navigation.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('presence.navigation.plural_model');
    }

    public static function table(Table $table): Table
    {
        return PresencePingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPresencePings::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
