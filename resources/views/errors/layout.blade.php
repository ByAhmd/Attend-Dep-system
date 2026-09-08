{{--
 | The page an employee lands on when a link no longer works.
 |
 | Laravel's own error pages are `<html lang="en">` with no direction and a
 | message taken from the exception, which is an English sentence written for
 | a developer. Until the leave attachment shipped nobody signed in could
 | reach one; a stale attachment link is now a perfectly ordinary way for an
 | employee to arrive here, so it reads in their language and runs in their
 | direction like every other screen.
 |
 | The styles are inline rather than the compiled theme on purpose. This is
 | the page that has to render when something is already wrong, and a missing
 | Vite manifest is one of the things that can be wrong: taking a dependency
 | on the build here would mean the failure page is the one page that fails.
--}}
@php
    $rtl = app()->getLocale() === 'ar';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $rtl ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="color-scheme" content="light dark">
        <title>@yield('title') — {{ config('app.name') }}</title>

        <style>
            :root {
                --ink: #1f2937;
                --muted: #4b5563;
                --paper: #f9fafb;
                --card: #ffffff;
                --line: rgb(15 23 42 / 0.08);
                --accent: #0f766e;
            }

            @media (prefers-color-scheme: dark) {
                :root {
                    --ink: #f3f4f6;
                    --muted: #9ca3af;
                    --paper: #111827;
                    --card: #1f2937;
                    --line: rgb(255 255 255 / 0.1);
                    --accent: #5eead4;
                }
            }

            * { box-sizing: border-box; }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1.5rem;
                background: var(--paper);
                color: var(--ink);
                font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto,
                    "Helvetica Neue", Arial, "Noto Sans Arabic", "Noto Sans", sans-serif;
                line-height: 1.7;
                -webkit-text-size-adjust: 100%;
            }

            .card {
                width: 100%;
                max-width: 30rem;
                background: var(--card);
                border: 1px solid var(--line);
                border-radius: 0.875rem;
                padding: 2rem 1.5rem;
                text-align: center;
                box-shadow: 0 1px 3px rgb(15 23 42 / 0.06);
            }

            .code {
                display: inline-block;
                font-size: 0.8125rem;
                font-weight: 600;
                letter-spacing: 0.08em;
                color: var(--muted);
                border: 1px solid var(--line);
                border-radius: 999px;
                padding: 0.125rem 0.75rem;
                margin-bottom: 1.25rem;
                /* A bare status number reorders inside an Arabic paragraph. */
                direction: ltr;
                unicode-bidi: isolate;
            }

            h1 {
                margin: 0 0 0.75rem;
                font-size: 1.25rem;
                font-weight: 600;
            }

            p {
                margin: 0 0 1.75rem;
                color: var(--muted);
                font-size: 0.9375rem;
            }

            a.back {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                /* 44px: this is read on a phone, like every employee screen. */
                min-height: 2.75rem;
                padding: 0 1.25rem;
                border-radius: 0.5rem;
                background: var(--accent);
                color: var(--card);
                font-size: 0.9375rem;
                font-weight: 600;
                text-decoration: none;
            }

            a.back:focus-visible {
                outline: 2px solid var(--accent);
                outline-offset: 2px;
            }
        </style>
    </head>
    <body>
        <main class="card">
            <span class="code">@yield('code')</span>

            <h1>@yield('title')</h1>

            <p>@yield('message')</p>

            <a class="back" href="{{ url('/') }}">{{ __('errors.back') }}</a>
        </main>
    </body>
</html>
