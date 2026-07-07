<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    {{-- Without JS, fall back to a meta refresh; a throttled (429) poll halts this chain, which the script below avoids. --}}
    <noscript>
        <meta http-equiv="refresh" content="2; url={{ $url }}" />
    </noscript>
    <title>{{ __('Preparing your download') }} - {{ config('app.name', 'Coffer') }}</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml" />
    <style>
        :root {
            color-scheme: dark;
        }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #27272a;
            color: #e4e4e7;
            font-family:
                ui-sans-serif,
                system-ui,
                -apple-system,
                "Segoe UI",
                Roboto,
                sans-serif;
        }
        .card {
            text-align: center;
            padding: 2rem;
            max-width: 28rem;
        }
        .spinner {
            width: 2.5rem;
            height: 2.5rem;
            margin: 0 auto 1.5rem;
            border: 3px solid #3f3f46;
            border-top-color: #818cf8;
            border-radius: 9999px;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
        h1 {
            font-size: 1.125rem;
            font-weight: 600;
            margin: 0 0 0.5rem;
        }
        p {
            font-size: 0.875rem;
            color: #a1a1aa;
            margin: 0;
        }
        a {
            color: #818cf8;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="spinner" role="status" aria-label="{{ __('Preparing your download') }}"></div>
        <h1>{{ __('Preparing your download…') }}</h1>
        <p>
            {{ __('We are packaging the files from :share into a zip. This page will refresh automatically and your download will begin when it is ready.', ['share' => $share->name]) }}
        </p>
        <noscript>
            <p style="margin-top: 1rem"><a href="{{ $url }}">{{ __('Refresh now') }}</a></p>
        </noscript>
    </div>
    <script>
        (function () {
            const url = @json ($url);
            const interval = 2000;

            function poll() {
                fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" }, credentials: "same-origin" })
                    .then(function (response) {
                        // 200 means the archive is ready; navigate to trigger the browser download.
                        if (response.status === 200) {
                            window.location.assign(url);
                            return;
                        }

                        // 429 means the poll was throttled; honour Retry-After, otherwise back off before retrying.
                        if (response.status === 429) {
                            const retryAfter = parseInt(response.headers.get("Retry-After") || "", 10);
                            setTimeout(poll, Number.isNaN(retryAfter) ? 5000 : retryAfter * 1000);
                            return;
                        }

                        // 202 (still preparing) or any transient error; keep polling.
                        setTimeout(poll, interval);
                    })
                    .catch(function () {
                        setTimeout(poll, interval);
                    });
            }

            setTimeout(poll, interval);
        })();
    </script>
</body>
</html>
