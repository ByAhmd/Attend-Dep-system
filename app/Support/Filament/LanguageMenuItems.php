<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Enums\Locale;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;

/**
 * The language entry of the user menu, shared by both panels.
 *
 * One item, labelled with the *other* language's own name: an Arabic
 * screen offers "English", an English screen offers "العربية". Resolved
 * through closures because the panel is configured once at boot while the
 * language is decided per request.
 */
final class LanguageMenuItems
{
    /**
     * @return array<string, Action>
     */
    public static function userMenuActions(): array
    {
        return [
            'switchLocale' => Action::make('switchLocale')
                ->label(fn (): string => Locale::current()->other()->nativeLabel())
                ->icon(Heroicon::OutlinedLanguage)
                ->url(fn (): string => route('locale.switch', Locale::current()->other()->value)),
        ];
    }
}
