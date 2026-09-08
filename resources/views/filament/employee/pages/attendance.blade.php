<x-filament-panels::page>
    @php
        /*
         | The three states, dressed.
         |
         | The page class decides which state the employee is in; this map
         | decides what it looks like. Each state is told apart three times
         | over - by its own glyph, by the shape of the chip holding it, and
         | by the sentence beside it - so the screen still says which one it
         | is in greyscale, in bright sunlight, or to someone who does not
         | see the green. The colour is the fastest of the three signals,
         | never the only one.
         |
         | Green here means what it means everywhere in this product: inside
         | the area, session open. The brand colour is deliberately absent
         | from this block - it says "press me", not "you are here".
         */
        $stateIcon = match ($state) {
            'checked_in' => 'heroicon-s-check-circle',
            'checked_out' => 'heroicon-o-flag',
            default => 'heroicon-o-map-pin',
        };

        // A 4px band across the top of the card: the one thing that is
        // readable from arm's length before a single word has been read.
        $stateAccent = match ($state) {
            'checked_in' => 'bg-success-500',
            'checked_out' => 'bg-gray-300 dark:bg-white/20',
            default => 'bg-gray-200 dark:bg-white/10',
        };

        /*
         | Filled and green while a session is open, filled and grey once the
         | day is closed, and an empty dashed outline before anything has
         | happened - three different shapes, not three tints of one.
         */
        $stateChip = match ($state) {
            'checked_in' => 'bg-success-50 text-success-700 ring-1 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/25',
            'checked_out' => 'bg-gray-100 text-gray-600 ring-1 ring-gray-950/10 dark:bg-white/10 dark:text-gray-300 dark:ring-white/15',
            default => 'border-2 border-dashed border-gray-300 text-gray-500 dark:border-gray-600 dark:text-gray-400',
        };

        /*
         | The verdict the server sent back with the last attempt. The 700
         | shades rather than 600: on white and on the grey inset this line
         | sits on, 600 measures 3.2:1 for green and 3.1:1 for amber, which
         | is below the 4.5:1 AA needs for text this size.
         */
        $feedbackClasses = match ($feedbackStatus) {
            'success' => 'text-success-700 dark:text-success-400',
            'danger' => 'text-danger-700 dark:text-danger-400',
            default => 'text-gray-600 dark:text-gray-300',
        };
    @endphp

    <div
        x-data="attendanceLocator({
            canCheckIn: @js($canCheckIn),
            canCheckOut: @js($canCheckOut),
            pingIntervalMs: @js($pingIntervalMs),
            messages: {
                locating: @js(__('attendance.feedback.locating')),
                verifying: @js(__('attendance.feedback.verifying')),
                unsupported: @js(__('attendance.feedback.unsupported')),
                insecureContext: @js(__('attendance.feedback.insecure_context')),
                permissionDenied: @js(__('attendance.feedback.permission_denied')),
                positionUnavailable: @js(__('attendance.feedback.position_unavailable')),
                timeout: @js(__('attendance.feedback.timeout')),
                failed: @js(__('attendance.feedback.failed')),
            },
        })"
        class="flex w-full flex-col gap-4 text-start"
    >
        {{--
         | One card carries the whole morning: who you are, where you stand,
         | how long you have been here, and the one button that changes it.
         | Nothing else on the screen competes with it, which is why it is
         | the only surface that is lifted off the page.
         --}}
        <div class="rounded-card shadow-lifted overflow-hidden bg-white ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="h-1 w-full {{ $stateAccent }}" aria-hidden="true"></div>

            <div class="flex flex-col gap-6 p-5 sm:p-6">
                <div class="flex flex-col gap-0.5">
                    <p class="text-base font-semibold text-gray-950 dark:text-white">
                        {{ __('attendance.page.greeting', ['name' => $employeeName]) }}
                    </p>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('attendance.page.today', ['date' => $todayLabel]) }}
                    </p>
                </div>

                {{--
                 | The state, given the room it deserves: a glyph a thumb's
                 | width across, the state in words at heading weight, and a
                 | sentence naming the time it happened. The eyebrow above it
                 | stays because it tells a screen reader what the heading
                 | that follows is the state OF.
                 --}}
                <div class="flex items-start gap-3.5">
                    <span class="{{ $stateChip }} flex size-12 shrink-0 items-center justify-center rounded-full">
                        <x-filament::icon :icon="$stateIcon" class="size-6" />
                    </span>

                    <div class="flex min-w-0 flex-col gap-0.5 pt-0.5">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('attendance.page.current_status') }}
                        </p>

                        <p class="text-xl font-bold leading-tight text-gray-950 dark:text-white">
                            {{ $stateLabel }}
                        </p>

                        <p class="text-sm text-gray-600 dark:text-gray-400">
                            {{ $stateCaption }}
                        </p>
                    </div>
                </div>

                {{--
                 | The number the day is actually about. It is set two steps
                 | larger than anything else on the screen because "how long
                 | have I been here" is the question the employee opens this
                 | page to answer, and the count of sessions is the footnote
                 | that explains it rather than a second headline.
                 --}}
                <dl class="flex items-end justify-between gap-4 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                    <div class="min-w-0">
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">
                            {{ __('attendance.page.total_inside') }}
                        </dt>

                        <dd class="mt-1 text-3xl font-bold leading-none tabular-nums text-gray-950 sm:text-4xl dark:text-white">
                            {{ $totalInside }}
                        </dd>
                    </div>

                    <div class="shrink-0 text-end">
                        <dt class="text-xs font-medium text-gray-600 dark:text-gray-400">
                            {{ __('attendance.page.sessions_count') }}
                        </dt>

                        <dd class="mt-1 text-xl font-semibold leading-none tabular-nums text-gray-950 dark:text-white">
                            {{ $sessionCount }}
                        </dd>
                    </div>
                </dl>

                {{--
                 | Today's sessions as a timeline rather than a table: on a
                 | phone three columns of times wrap into nonsense, while a
                 | rail of markers says at a glance how many times the day
                 | was interrupted and which of them is still running.
                 --}}
                <div class="flex flex-col gap-3">
                    <p class="text-sm font-medium text-gray-600 dark:text-gray-400">
                        {{ __('attendance.page.sessions_heading') }}
                    </p>

                    @if (filled($sessions))
                        <ol class="flex flex-col">
                            @foreach ($sessions as $session)
                                <li class="flex gap-3">
                                    {{-- The rail. A flex column rather than an
                                         absolutely positioned line, so it
                                         follows the reading direction on its
                                         own. --}}
                                    <div class="flex w-3 shrink-0 flex-col items-center pt-1.5" aria-hidden="true">
                                        @if ($session['isOpen'])
                                            <span class="ring-success-500/25 size-3 shrink-0 rounded-full bg-success-500 ring-4"></span>
                                        @else
                                            <span class="size-3 shrink-0 rounded-full bg-gray-300 dark:bg-gray-600"></span>
                                        @endif

                                        @unless ($loop->last)
                                            <span class="mt-1 w-px grow bg-gray-200 dark:bg-white/10"></span>
                                        @endunless
                                    </div>

                                    <div @class(['min-w-0 flex-1', 'pb-4' => ! $loop->last])>
                                        {{-- The duration sits beside the range
                                             it measures rather than at the far
                                             end of the row: on a wide screen a
                                             justified pair strands them 400px
                                             apart and stops reading as one
                                             statement. --}}
                                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            {{--
                                             | dir="ltr": a time range is a run
                                             | of neutral characters, and in the
                                             | Arabic page the bidirectional
                                             | algorithm would otherwise print
                                             | the check-out first.
                                             --}}
                                            <span dir="ltr" class="text-base font-semibold tabular-nums text-gray-950 dark:text-white">
                                                {{ $session['checkIn'] }} &ndash; {{ $session['checkOut'] }}
                                            </span>

                                            @unless ($session['isOpen'])
                                                <span class="rounded-md bg-gray-100 px-2 py-0.5 text-xs font-medium tabular-nums text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                                    {{ $session['duration'] }}
                                                </span>
                                            @endunless
                                        </div>

                                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                                            {{ __('attendance.page.session_number', ['number' => $session['number']]) }}
                                        </p>
                                    </div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <div class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-gray-300 px-4 py-6 text-center dark:border-gray-700">
                            <x-filament::icon
                                icon="heroicon-o-clock"
                                class="size-6 text-gray-400 dark:text-gray-500"
                            />

                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                {{ __('attendance.page.sessions_empty') }}
                            </p>
                        </div>
                    @endif
                </div>

                {{--
                 | The actions, live one first. Exactly one of them can be
                 | pressed at a time, so the page class puts that one at the
                 | head of the list and it is drawn full height; the other
                 | follows, disabled and quiet, so nobody hunts for a button
                 | that was never removed.
                 --}}
                <div class="flex flex-col gap-2.5">
                    @foreach ($actions as $action)
                        <x-filament::button
                            :color="$loop->first ? ($action['key'] === 'checkIn' ? 'success' : 'primary') : 'gray'"
                            :size="$loop->first ? 'xl' : 'lg'"
                            @class([
                                'w-full',
                                'min-h-14 text-base font-semibold' => $loop->first,
                                'min-h-11' => ! $loop->first,
                            ])
                            :disabled="! $action['enabled']"
                            x-on:click="submit('{{ $action['key'] }}')"
                            x-bind:disabled="isBusy || ! {{ $action['alpineFlag'] }}"
                        >
                            <span class="inline-flex items-center gap-2">
                                <x-filament::loading-indicator
                                    x-cloak
                                    x-show="pending === '{{ $action['key'] }}'"
                                    class="h-5 w-5"
                                />

                                <span>{{ $action['label'] }}</span>
                            </span>
                        </x-filament::button>
                    @endforeach
                </div>

                {{--
                 | The location strip: what the phone is doing, and the rule
                 | it is being measured against. It is always on the screen,
                 | in the same place, so the sentence that appears when
                 | something goes wrong arrives where the employee is already
                 | looking instead of somewhere new.
                 --}}
                <div class="flex items-start gap-2.5 rounded-xl bg-gray-50 p-3.5 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <x-filament::icon
                        :icon="$isLocationConfigured ? 'heroicon-o-map-pin' : 'heroicon-s-exclamation-triangle'"
                        @class([
                            'mt-px size-5 shrink-0',
                            'text-gray-500 dark:text-gray-400' => $isLocationConfigured,
                            'text-warning-700 dark:text-warning-400' => ! $isLocationConfigured,
                        ])
                    />

                    <div class="flex min-w-0 flex-1 flex-col gap-1">
                        <div role="status" aria-live="polite" class="text-sm font-medium">
                            <p
                                x-cloak
                                x-show="message !== null"
                                x-text="message"
                                x-bind:class="{
                                    'text-danger-700 dark:text-danger-400': status === 'danger',
                                    'text-gray-700 dark:text-gray-200': status !== 'danger',
                                }"
                            ></p>

                            @if (filled($feedbackMessage))
                                <p x-show="message === null" @class([$feedbackClasses])>
                                    {{ $feedbackMessage }}
                                </p>
                            @endif
                        </div>

                        @if ($isLocationConfigured)
                            <p class="text-xs text-gray-600 dark:text-gray-400">
                                {{ __('attendance.page.radius_hint', ['radius' => $radiusMeters]) }}
                            </p>
                        @else
                            <p class="text-xs font-medium text-warning-700 dark:text-warning-400">
                                {{ __('attendance.page.location_not_configured') }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{--
         | How the screen behaves, kept off the card and under it: true, worth
         | knowing once, and never in the way of the button.
         --}}
        @php
            $hints = [
                'heroicon-o-arrow-path' => __('attendance.page.sessions_hint'),
                'heroicon-o-map-pin' => __('attendance.page.location_hint'),
                'heroicon-o-signal' => __('presence.employee.hint'),
            ];
        @endphp

        <ul class="flex flex-col gap-2 px-1 text-xs leading-relaxed text-gray-600 dark:text-gray-400">
            @foreach ($hints as $hintIcon => $hint)
                <li class="flex items-start gap-2">
                    <x-filament::icon
                        :icon="$hintIcon"
                        class="mt-0.5 size-4 shrink-0 text-gray-400 dark:text-gray-500"
                    />

                    <span>{{ $hint }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <script>
        /*
         | Runs before Filament's bundle (which sits at the end of <body>)
         | starts Alpine, so registering on alpine:init is early enough. The
         | browser only ever reports where it is and how sure it is; the
         | server does every measurement.
         */
        document.addEventListener('alpine:init', () => {
            Alpine.data('attendanceLocator', ({ canCheckIn, canCheckOut, pingIntervalMs, messages }) => ({
                canCheckIn,
                canCheckOut,
                state: 'idle',
                pending: null,
                message: null,
                status: 'info',
                pingTimer: null,
                onVisibilityChange: null,

                init() {
                    /*
                     | The presence loop lives beside the buttons but never
                     | in front of them: it starts itself when a session is
                     | open, and the visibility listener stops it the moment
                     | the page goes to the background.
                     */
                    this.onVisibilityChange = () => this.schedulePings()

                    document.addEventListener('visibilitychange', this.onVisibilityChange)

                    this.schedulePings()
                },

                destroy() {
                    this.stopPings()

                    document.removeEventListener('visibilitychange', this.onVisibilityChange)
                },

                get isBusy() {
                    return this.state !== 'idle'
                },

                async submit(action) {
                    if (this.state !== 'idle') {
                        return
                    }

                    if (! ('geolocation' in navigator)) {
                        this.fail(messages.unsupported)

                        return
                    }

                    if (! window.isSecureContext) {
                        this.fail(messages.insecureContext)

                        return
                    }

                    this.pending = action
                    this.transition('locating', messages.locating)

                    let reading

                    try {
                        reading = await this.locate()
                    } catch (reason) {
                        this.fail(messages[reason] ?? messages.positionUnavailable)

                        return
                    }

                    this.transition('verifying', messages.verifying)

                    try {
                        await this.$wire[action]({
                            latitude: reading.latitude,
                            longitude: reading.longitude,
                            accuracy: reading.accuracy,
                        })
                    } catch (error) {
                        this.fail(messages.failed)

                        return
                    }

                    // The server has re-rendered its own verdict; let it show.
                    this.message = null
                    this.status = 'info'
                    this.state = 'idle'
                    this.pending = null

                    // That round trip may have opened or closed the session.
                    this.schedulePings()
                },

                /*
                 | Presence pings: while a session is open and this page is
                 | in front of the employee, the browser reports where it is
                 | every few minutes. Supporting evidence and nothing more -
                 | a phone only reports while its page is open and awake, so
                 | the loop stops with the page and the gaps it leaves prove
                 | nothing about where anybody was.
                 |
                 | It is silent from end to end: no message, no spinner, no
                 | button state. Whatever goes wrong - no permission, no fix,
                 | no connection, a throttled request - the employee is told
                 | nothing, because none of it is theirs to act on.
                 */
                get shouldPing() {
                    return pingIntervalMs > 0
                        && this.$wire.sessionIsOpen
                        && ! document.hidden
                        && 'geolocation' in navigator
                        && window.isSecureContext
                },

                schedulePings() {
                    if (! this.shouldPing) {
                        this.stopPings()

                        return
                    }

                    if (this.pingTimer === null) {
                        this.pingTimer = setInterval(() => this.sendPing(), pingIntervalMs)
                    }
                },

                stopPings() {
                    if (this.pingTimer !== null) {
                        clearInterval(this.pingTimer)

                        this.pingTimer = null
                    }
                },

                async sendPing() {
                    // Conditions are re-read on every tick: the session may
                    // have been closed in another tab, and the page may have
                    // been hidden between two of them.
                    if (! this.shouldPing) {
                        this.stopPings()

                        return
                    }

                    // A check-in or check-out is being verified. Its reading
                    // is the one that decides something; a ping waits for
                    // the next tick rather than competing with it.
                    if (this.isBusy) {
                        return
                    }

                    let reading

                    try {
                        reading = await this.locate()
                    } catch {
                        return
                    }

                    // The session may have closed while the fix was being taken.
                    if (! this.shouldPing || this.isBusy) {
                        return
                    }

                    try {
                        await this.$wire.ping({
                            latitude: reading.latitude,
                            longitude: reading.longitude,
                            accuracy: reading.accuracy,
                        })
                    } catch {
                        // A ping that never arrives is simply a ping that
                        // was never recorded.
                    }
                },

                /*
                 | Watches the position for up to ten seconds, keeping the most
                 | accurate fix seen, and stops early once a fix is within 25 m.
                 | A phone's first fix is often a coarse network guess; waiting
                 | a moment for GPS avoids refusing someone who is at the door.
                 */
                locate() {
                    return new Promise((resolve, reject) => {
                        let best = null
                        let watchId = null
                        let settled = false

                        const finish = (outcome) => {
                            if (settled) {
                                return
                            }

                            settled = true
                            clearTimeout(fallback)

                            if (watchId !== null) {
                                navigator.geolocation.clearWatch(watchId)
                            }

                            outcome()
                        }

                        const settle = (reason) => finish(() => best !== null ? resolve(best) : reject(reason))

                        const fallback = setTimeout(() => settle('timeout'), 10000)

                        watchId = navigator.geolocation.watchPosition(
                            (position) => {
                                const reading = {
                                    latitude: position.coords.latitude,
                                    longitude: position.coords.longitude,
                                    accuracy: position.coords.accuracy,
                                }

                                if (best === null || reading.accuracy < best.accuracy) {
                                    best = reading
                                }

                                if (reading.accuracy <= 25) {
                                    finish(() => resolve(best))
                                }
                            },
                            (error) => settle({ 1: 'permissionDenied', 2: 'positionUnavailable', 3: 'timeout' }[error.code] ?? 'positionUnavailable'),
                            { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
                        )
                    })
                },

                transition(state, message) {
                    this.state = state
                    this.message = message
                    this.status = 'info'
                },

                fail(message) {
                    this.state = 'idle'
                    this.pending = null
                    this.message = message
                    this.status = 'danger'
                },
            }))
        })
    </script>
</x-filament-panels::page>
