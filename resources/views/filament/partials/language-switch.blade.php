{{--
    The language link above the login form. The user menu is not available
    before sign-in, and someone who cannot read the login page needs the
    switch most.
--}}
<div class="mb-6 flex justify-center">
    <x-filament::link
        :href="route('locale.switch', $other->value)"
        icon="heroicon-m-language"
        color="gray"
        size="sm"
        rel="nofollow"
        :title="__('app.language')"
    >
        {{ $other->nativeLabel() }}
    </x-filament::link>
</div>
