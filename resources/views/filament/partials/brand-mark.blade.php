{{--
    The brand mark: a location pin and the name beside it.

    "مكاني" means "my place", and the whole product is one promise - that you
    are where you said you are - so the pin is the identity rather than a
    decoration on top of it.

    Filament renders this inside .fi-logo, which fixes the height and sets the
    text colour, so the name takes currentColor and only the pin is tinted.
    That way one asset serves the light and the dark theme, and the name is
    live text in the interface font rather than an outline, so it stays sharp
    at any size and is read aloud as the brand name.
--}}
<span class="fi-brand-mark">
    <svg
        class="fi-brand-mark-pin"
        viewBox="0 0 24 24"
        fill="currentColor"
        aria-hidden="true"
        focusable="false"
    >
        <path
            fill-rule="evenodd"
            clip-rule="evenodd"
            d="M12 2.25a7.5 7.5 0 0 0-7.5 7.5c0 4.62 5.53 10.5 7.02 11.99a.68.68 0 0 0 .96 0c1.49-1.49 7.02-7.37 7.02-11.99a7.5 7.5 0 0 0-7.5-7.5Zm0 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z"
        />
    </svg>

    <span class="fi-brand-mark-name">{{ __('app.name') }}</span>
</span>
