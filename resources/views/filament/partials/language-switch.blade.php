{{--
    The language switch on every signed-out screen - sign in, and the reset
    page an invited employee lands on. The user menu that carries it after
    sign-in does not exist yet, and someone who cannot read the page in front
    of them needs the switch most, so it sits at the top of the layout, first
    in the reading and tab order, before the form.

    It is a control rather than a bare link: 44px tall so a thumb can hit it,
    and labelled in the language it leads to, tagged with that language so a
    screen reader pronounces "English" in English and "العربية" in Arabic.
--}}
<div class="fi-auth-topbar">
    <a
        class="fi-auth-lang"
        href="{{ route('locale.switch', $other->value) }}"
        rel="nofollow"
        lang="{{ $other->value }}"
        title="{{ __('app.switch_language', ['language' => $other->nativeLabel()]) }}"
    >
        <x-filament::icon
            icon="heroicon-m-language"
            class="fi-auth-lang-icon"
        />

        <span>{{ $other->nativeLabel() }}</span>
    </a>
</div>
