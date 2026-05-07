<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="auth-layout nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg antialiased selection:bg-green-500/30 dark:bg-zinc-950">
        <div class="relative flex min-h-svh flex-col items-center justify-start gap-4 overflow-y-auto px-5 py-5 sm:justify-center sm:gap-6 md:p-10">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-52 bg-app-shell dark:bg-green-950/30"></div>
            <div class="pointer-events-none absolute inset-x-0 top-52 h-px bg-white/30"></div>

            <div class="relative flex w-full max-w-md flex-col gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-[1.75rem] bg-white/10 px-4 py-3 text-white ring-1 ring-white/10 backdrop-blur" wire:navigate>
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-green-200">{{ __('Autoreach') }}</p>
                        <p class="truncate text-base font-black leading-tight">{{ __('Bingwa') }}</p>
                    </div>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>
                <main class="flex flex-col gap-5 sm:gap-6">
                    {{ $slot }}
                </main>
            </div>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        <script>
            (() => {
                if (window.__bingwaSetupPermissionsRequested) {
                    return;
                }

                window.__bingwaSetupPermissionsRequested = true;

                const storageKey = 'bingwa.setupPermissions.granted.v1';

                if (window.localStorage.getItem(storageKey) === '1') {
                    return;
                }

                const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content || '';

                window.setTimeout(async () => {
                    try {
                        const response = await fetch('/_native/api/call', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': csrfToken(),
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body: JSON.stringify({
                                method: 'RequestSetupPermissions',
                                params: {
                                    openSpecialSettings: false,
                                },
                            }),
                        });

                        if (!response.ok) {
                            return;
                        }

                        const result = await response.json();
                        const nativeData = result.data?.data ?? result.data ?? {};

                        if (nativeData.runtimePermissionsGranted === true) {
                            window.localStorage.setItem(storageKey, '1');
                        }
                    } catch (error) {
                        // Native bridge is only available inside the packaged mobile app.
                    }
                }, 1200);
            })();
        </script>

        @fluxScripts
    </body>
</html>
