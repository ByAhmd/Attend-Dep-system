@props(['hasDate', 'rows'])

{{--
 | What the system actually holds for the chosen day, printed above the time
 | fields that propose to change it.
 |
 | This block is the whole point of the form's ordering. Somebody about to
 | type 08:00 needs to see that the device wrote 08:47 before they type it,
 | not after an administrator has rejected them for asking to correct a time
 | that was already right. And when the day holds nothing, saying so plainly
 | is the answer to the commonest reason for opening this form at all.
 |
 | The tag under each pair says where the time came from. A session the
 | device recorded wears "recorded from your location"; a session an approved
 | correction already amended must not, because that claim is precisely the
 | one the correction withdrew.
 --}}
@if (! $hasDate)
    <p class="text-sm text-gray-500 dark:text-gray-400">
        {{ __('corrections.sessions.pick_date') }}
    </p>
@elseif (empty($rows))
    <div class="flex items-start gap-2 rounded-lg border border-dashed border-gray-300 px-3 py-2.5 dark:border-gray-700">
        <x-filament::icon
            icon="heroicon-o-clock"
            class="mt-px size-4 shrink-0 text-gray-400 dark:text-gray-500"
        />

        <p class="text-sm text-gray-600 dark:text-gray-400">
            {{ __('corrections.sessions.none') }}
        </p>
    </div>
@else
    <ul class="flex flex-col gap-2">
        @foreach ($rows as $row)
            <li class="flex flex-col gap-1 rounded-lg bg-gray-50 px-3 py-2.5 dark:bg-white/5">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    {{-- dir="ltr": a time range is a run of neutral
                         characters, and in the Arabic page the bidirectional
                         algorithm would otherwise print the check-out first. --}}
                    <span dir="ltr" class="text-sm font-semibold tabular-nums text-gray-950 dark:text-white">
                        {{ $row['range'] }}
                    </span>

                    @if ($row['duration'] !== null)
                        <span class="text-xs tabular-nums text-gray-500 dark:text-gray-400">
                            {{ $row['duration'] }}
                        </span>
                    @endif
                </div>

                <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <span class="text-gray-500 dark:text-gray-400">
                        {{ __('corrections.sessions.option', ['number' => $row['number']]) }}
                    </span>

                    @if ($row['isCorrected'])
                        <span class="inline-flex items-center gap-1 rounded-md bg-info-50 px-1.5 py-0.5 font-medium text-info-700 dark:bg-info-400/10 dark:text-info-400">
                            <x-filament::icon icon="heroicon-m-pencil-square" class="size-3.5" />
                            {{ __('attendance.badges.corrected') }}
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-1.5 py-0.5 font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                            <x-filament::icon icon="heroicon-m-map-pin" class="size-3.5" />
                            {{ __('corrections.sessions.device_recorded') }}
                        </span>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif
