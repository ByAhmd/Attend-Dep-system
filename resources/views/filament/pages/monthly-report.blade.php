<x-filament-panels::page>
    <div class="flex w-full flex-col gap-4">
        {{--
         | The month, and what the figures below are measured against. The
         | native month input is the one picker a phone and a desktop both
         | ship; Livewire re-renders the table the moment it changes, so
         | there is no button to press and no stale table to misread.
         --}}
        <div class="flex flex-wrap items-end justify-between gap-4 rounded-xl bg-white p-4 ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <label class="flex flex-col gap-1">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ __('reports.fields.month') }}
                </span>

                <input
                    type="month"
                    wire:model.live="month"
                    class="rounded-lg border-gray-300 bg-white text-sm text-gray-950 shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-white"
                />
            </label>

            <div class="text-end">
                <p class="text-sm font-medium text-gray-700 dark:text-gray-200">
                    {{ $monthLabel }}
                </p>

                <p class="text-xs text-gray-500 dark:text-gray-400">
                    {{ __('reports.working_days_elapsed') }}
                </p>

                <p class="mt-0.5 text-2xl font-bold leading-none tabular-nums text-gray-950 dark:text-white">
                    {{ $workingDaysElapsed }}
                </p>
            </div>
        </div>

        @if (filled($rows))
            <div class="overflow-x-auto rounded-xl bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                <table class="w-full min-w-[56rem] text-start text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.employee') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.days_attended') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.time_inside') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.late_days') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.total_lateness') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.early_check_outs') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.leave_days') }}</th>
                            <th class="px-4 py-3 text-start font-medium">{{ __('reports.fields.days_unrecorded') }}</th>
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($rows as $row)
                            <tr @class(['border-b border-gray-100 dark:border-white/5' => ! $loop->last])>
                                <td class="px-4 py-3">
                                    <p class="font-semibold text-gray-950 dark:text-white">{{ $row->employeeName }}</p>

                                    @if ($row->jobTitle !== null)
                                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $row->jobTitle }}</p>
                                    @endif
                                </td>

                                <td class="px-4 py-3 tabular-nums text-gray-950 dark:text-white">{{ $row->daysAttended }}</td>
                                <td class="px-4 py-3 tabular-nums font-medium text-gray-950 dark:text-white">{{ $formatDuration($row->secondsInside) }}</td>
                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $row->lateDays }}</td>
                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $formatDuration($row->latenessSeconds) }}</td>
                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $row->earlyCheckOuts }}</td>
                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $row->leaveDays }}</td>
                                <td class="px-4 py-3 tabular-nums text-gray-700 dark:text-gray-300">{{ $row->daysUnrecorded }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @else
            <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-10 text-center dark:border-gray-700">
                <x-filament::icon icon="heroicon-o-document-chart-bar" class="size-6 text-gray-400 dark:text-gray-500" />

                <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ __('reports.empty') }}
                </p>
            </div>
        @endif

        {{--
         | What the figures mean, stated under the table that shows them:
         | the vocabulary here is careful on purpose, and the reader must
         | not have to guess at it.
         --}}
        <ul class="flex flex-col gap-2 px-1 text-xs leading-relaxed text-gray-600 dark:text-gray-400">
            <li class="flex items-start gap-2">
                <x-filament::icon icon="heroicon-o-information-circle" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                <span>{{ __('reports.hints.unrecorded') }}</span>
            </li>

            <li class="flex items-start gap-2">
                <x-filament::icon icon="heroicon-o-clock" class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500" />
                <span>{{ __('reports.hints.lateness') }}</span>
            </li>
        </ul>
    </div>
</x-filament-panels::page>
