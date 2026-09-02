<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The two interface languages.
 *
 * Arabic is the default and English the fallback; every user-facing string
 * exists in both. The enum is the single list of what may be chosen, so the
 * switch route, the middleware and the menu can never disagree.
 */
enum Locale: string
{
    case Arabic = 'ar';
    case English = 'en';

    /**
     * Each language names itself, deliberately untranslated: a reader who
     * wants to switch must be able to read the option in their own script.
     */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::Arabic => 'العربية',
            self::English => 'English',
        };
    }

    public function other(): self
    {
        return $this === self::Arabic ? self::English : self::Arabic;
    }

    /**
     * The language of the current request, as applied by SetLocale.
     */
    public static function current(): self
    {
        return self::tryFrom(app()->getLocale())
            ?? self::tryFrom((string) config('app.fallback_locale'))
            ?? self::Arabic;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
