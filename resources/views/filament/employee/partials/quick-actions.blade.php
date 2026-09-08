@props(['tiles'])

{{--
 | The quick-action band.
 |
 | Three rows and not three columns, at every width. The captions are
 | sentences - "no allowance left this month", "2 awaiting a decision" - and
 | a sentence in a 96px column at 320px wraps into four lines of two words.
 | A row gives each tile the whole measure, keeps every tap target one thumb
 | tall, and reads as the short menu it is on a phone and on a desktop alike.
 |
 | Flat, with a hairline divider between rows. The attendance card above it
 | is the one lifted surface on that screen; a second raised cluster would
 | make a form nobody opens weekly compete with the button somebody presses
 | every morning.
 |
 | The two request tiles mount a Filament modal with wire:click, so the band
 | needs no JavaScript of its own and works before Alpine has started. The
 | third is an ordinary link, which is what a page is.
 --}}
<div class="flex flex-col gap-2">
    <p class="px-1 text-sm font-medium text-gray-600 dark:text-gray-400">
        {{ __('requests.tiles.heading') }}
    </p>

    <div class="divide-y divide-gray-200 overflow-hidden rounded-xl bg-white ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10">
        @foreach ($tiles as $tile)
            @php
                $tag = $tile['href'] === null ? 'button' : 'a';
            @endphp

            <{{ $tag }}
                @if ($tile['href'] === null)
                    type="button"
                    wire:click="mountAction('{{ $tile['action'] }}')"
                    wire:loading.attr="disabled"
                @else
                    href="{{ $tile['href'] }}"
                @endif
                class="flex min-h-14 w-full items-center gap-3 px-4 py-3 text-start transition hover:bg-gray-50 focus-visible:bg-gray-50 focus-visible:outline-none dark:hover:bg-white/5 dark:focus-visible:bg-white/5"
            >
                <span class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-gray-100 text-gray-500 dark:bg-white/10 dark:text-gray-400">
                    <x-filament::icon :icon="$tile['icon']" class="size-5" />
                </span>

                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="text-sm font-semibold text-gray-950 dark:text-white">
                        {{ $tile['label'] }}
                    </span>

                    <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ $tile['caption'] }}
                    </span>
                </span>

                {{-- Points the way the page reads, so it still means "onwards"
                     in Arabic. --}}
                <x-filament::icon
                    icon="heroicon-m-chevron-right"
                    class="size-4 shrink-0 text-gray-400 rtl:-scale-x-100 dark:text-gray-500"
                    aria-hidden="true"
                />
            </{{ $tag }}>
        @endforeach
    </div>
</div>
