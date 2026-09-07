<x-filament-panels::page>
    @php
        $feedbackClasses = match ($feedbackStatus) {
            'success' => 'text-success-600 dark:text-success-400',
            'danger' => 'text-danger-600 dark:text-danger-400',
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
        class="mx-auto flex w-full max-w-md flex-col gap-6 text-start"
    >
        <x-filament::section>
            <div class="flex flex-col gap-5">
                <div class="flex flex-col gap-1">
                    <p class="text-lg font-semibold text-gray-950 dark:text-white">
                        {{ __('attendance.page.greeting', ['name' => $employeeName]) }}
                    </p>

                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('attendance.page.today', ['date' => $todayLabel]) }}
                    </p>
                </div>

                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ __('attendance.page.current_status') }}
                    </span>

                    <x-filament::badge :color="$stateColor" size="lg">
                        {{ $stateLabel }}
                    </x-filament::badge>
                </div>

                <dl class="grid grid-cols-2 gap-3">
                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('attendance.page.total_inside') }}
                        </dt>

                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ $totalInside }}
                        </dd>
                    </div>

                    <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                        <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">
                            {{ __('attendance.page.sessions_count') }}
                        </dt>

                        <dd class="mt-1 text-2xl font-semibold tabular-nums text-gray-950 dark:text-white">
                            {{ $sessionCount }}
                        </dd>
                    </div>
                </dl>

                {{--
                 | Today's sessions, oldest first, as a list rather than a
                 | table: on a phone three columns of times wrap into
                 | nonsense, and one line per session stays readable at any
                 | width.
                 --}}
                <div class="flex flex-col gap-2">
                    <p class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ __('attendance.page.sessions_heading') }}
                    </p>

                    @forelse ($sessions as $session)
                        <div class="flex items-center justify-between gap-3 rounded-lg bg-gray-50 p-3 text-sm dark:bg-white/5">
                            <span class="shrink-0 text-xs font-medium text-gray-500 dark:text-gray-400">
                                {{ __('attendance.page.session_number', ['number' => $session['number']]) }}
                            </span>

                            {{--
                             | dir="ltr": a time range is a run of neutral
                             | characters, and in the Arabic page the
                             | bidirectional algorithm would otherwise print
                             | the check-out first.
                             --}}
                            <span dir="ltr" class="font-semibold tabular-nums text-gray-950 dark:text-white">
                                {{ $session['checkIn'] }} &ndash; {{ $session['checkOut'] }}
                            </span>

                            <span class="shrink-0 tabular-nums text-gray-500 dark:text-gray-400">
                                {{ $session['duration'] }}
                            </span>
                        </div>
                    @empty
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ __('attendance.page.sessions_empty') }}
                        </p>
                    @endforelse
                </div>

                <div class="flex flex-col gap-3">
                    <x-filament::button
                        size="xl"
                        color="success"
                        class="w-full"
                        :disabled="! $canCheckIn"
                        x-on:click="submit('checkIn')"
                        x-bind:disabled="isBusy || ! canCheckIn"
                    >
                        <span class="inline-flex items-center gap-2">
                            <x-filament::loading-indicator
                                x-cloak
                                x-show="pending === 'checkIn'"
                                class="h-5 w-5"
                            />

                            <span>{{ __('attendance.actions.check_in') }}</span>
                        </span>
                    </x-filament::button>

                    <x-filament::button
                        size="xl"
                        color="gray"
                        class="w-full"
                        :disabled="! $canCheckOut"
                        x-on:click="submit('checkOut')"
                        x-bind:disabled="isBusy || ! canCheckOut"
                    >
                        <span class="inline-flex items-center gap-2">
                            <x-filament::loading-indicator
                                x-cloak
                                x-show="pending === 'checkOut'"
                                class="h-5 w-5"
                            />

                            <span>{{ __('attendance.actions.check_out') }}</span>
                        </span>
                    </x-filament::button>
                </div>

                <div role="status" aria-live="polite" class="min-h-6 text-sm">
                    <p
                        x-cloak
                        x-show="message !== null"
                        x-text="message"
                        x-bind:class="{
                            'text-danger-600 dark:text-danger-400': status === 'danger',
                            'text-gray-600 dark:text-gray-300': status !== 'danger',
                        }"
                    ></p>

                    @if (filled($feedbackMessage))
                        <p x-show="message === null" @class([$feedbackClasses])>
                            {{ $feedbackMessage }}
                        </p>
                    @endif
                </div>

                <div class="flex flex-col gap-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($isLocationConfigured)
                        <p>{{ __('attendance.page.radius_hint', ['radius' => $radiusMeters]) }}</p>
                    @else
                        <p class="font-medium text-warning-600 dark:text-warning-400">
                            {{ __('attendance.page.location_not_configured') }}
                        </p>
                    @endif

                    <p>{{ __('attendance.page.sessions_hint') }}</p>

                    <p>{{ __('attendance.page.location_hint') }}</p>

                    <p>{{ __('presence.employee.hint') }}</p>
                </div>
            </div>
        </x-filament::section>
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
