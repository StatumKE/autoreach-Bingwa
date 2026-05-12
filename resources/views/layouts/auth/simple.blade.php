<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body 
        class="auth-layout nativephp-safe-area min-h-screen overflow-x-hidden bg-app-bg antialiased selection:bg-green-500/30 dark:bg-zinc-950"
        x-data="bingwaPermissionSetup()"
        x-init="requestSetupPermissionsOnce()"
    >
        <div class="relative flex min-h-svh flex-col items-center justify-start gap-4 overflow-y-auto px-5 py-5 sm:justify-center sm:gap-6 md:p-10">
            <div class="pointer-events-none absolute inset-x-0 top-0 h-52 bg-app-shell dark:bg-green-950/30"></div>
            <div class="pointer-events-none absolute inset-x-0 top-52 h-px bg-white/30"></div>

            <div class="relative flex w-full max-w-md flex-col gap-4">
                <a href="{{ route('home') }}" class="flex items-center gap-3 rounded-[1.75rem] bg-white/10 px-4 py-3 text-white ring-1 ring-white/10 backdrop-blur">
                    <div class="min-w-0">
                        <p class="text-[10px] font-black uppercase tracking-[0.22em] text-green-200">{{ __('Autoreach') }}</p>
                        <p class="truncate text-base font-black leading-tight">{{ __('Bingwa') }}</p>
                    </div>
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div id="auth-permission-warning" class="hidden rounded-xl bg-amber-50 px-4 py-4 ring-1 ring-amber-200 dark:bg-amber-900/20 dark:ring-amber-900 shadow-sm">
                    <div class="flex items-start gap-3">
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                        <div class="min-w-0">
                            <h3 class="text-sm font-bold text-amber-800 dark:text-amber-200">{{ __('Device Permissions Needed') }}</h3>
                            <p class="mt-1 text-xs leading-relaxed text-amber-700 dark:text-amber-300">
                                {{ __('Bingwa requires Phone and Contacts access to process USSD transactions. Please grant access to continue.') }}
                            </p>
                            <button 
                                type="button"
                                x-on:click="requestSetupPermissionsOnce(true)"
                                class="mt-3 rounded-lg bg-amber-200/50 px-4 py-2 text-xs font-bold text-amber-900 transition hover:bg-amber-200 active:scale-95 dark:bg-amber-800/50 dark:text-amber-100 dark:hover:bg-amber-800"
                            >
                                {{ __('Grant Permissions') }}
                            </button>
                        </div>
                    </div>
                </div>

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
            function bingwaPermissionSetup() {
                return {
                    requestSetupPermissionsOnce(force = false) {
                        const sessionKey = 'bingwa-setup-permissions-requested-v1';

                        if (sessionStorage.getItem(sessionKey)) {
                            return;
                        }

                        sessionStorage.setItem(sessionKey, '1');

                        fetch('/_native/api/call', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                            },
                            body: JSON.stringify({
                                method: 'RequestSetupPermissions',
                                params: {
                                    force,
                                    openSpecialSettings: false,
                                },
                            }),
                        }).then(res => res.json()).then(data => {
                            if (data && data.runtimePermissionsGranted === false) {
                                document.getElementById('auth-permission-warning')?.classList.remove('hidden');
                            } else if (data && data.runtimePermissionsGranted === true) {
                                document.getElementById('auth-permission-warning')?.classList.add('hidden');
                            }
                        }).catch(() => {
                            if (force) {
                                sessionStorage.removeItem(sessionKey);
                            }
                        });
                    },
                };
            }
        </script>

        @fluxScripts
    </body>
</html>
