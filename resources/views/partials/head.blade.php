<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="theme-color" content="#bedbff" />
<meta name="color-scheme" content="light dark" />
<meta name="csrf-token" content="{{ csrf_token() }}" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Laravel') : config('app.name', 'Laravel') }}
</title>

<link rel="icon" href="{{ Vite::asset('resources/images/favicon.ico') }}" sizes="any">
<link rel="icon" href="{{ Vite::asset('resources/images/favicon.svg') }}" type="image/svg+xml">
<link rel="apple-touch-icon" href="{{ Vite::asset('resources/images/apple-touch-icon.png') }}">

@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
    if (! window.localStorage.getItem('flux.appearance')) {
        window.localStorage.setItem('flux.appearance', 'light');
    }
</script>
@auth
    @php
        $broadcastRegistration = auth()->user()?->bingwaDeviceRegistration;
        $broadcastingConfig = $broadcastRegistration !== null && filled($broadcastRegistration->device_token)
            ? [
                'authEndpoint' => rtrim(config('services.autoreach.backend_url'), '/').'/api/v1/broadcasting/auth',
                'deviceToken' => $broadcastRegistration->device_token,
            ]
            : null;
    @endphp

    @if ($broadcastingConfig !== null)
        <script>
            window.__autoreachBroadcasting = {!! json_encode($broadcastingConfig, JSON_THROW_ON_ERROR) !!};
        </script>
    @endif
@endauth
@fluxAppearance
