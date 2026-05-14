<x-layouts::app.sidebar :title="__('Device settings')">
    <section class="min-h-screen bg-zinc-50 px-4 pb-24 pt-4">
        <div class="flex flex-col gap-4">
            <header class="px-1">
                <h1 class="text-xl font-black tracking-tight text-zinc-900">{{ __('Device Configuration') }}</h1>
                <p class="text-xs text-zinc-500">{{ __('Manage hardware and automation rules for this device.') }}</p>
            </header>

            @if (session('status'))
                <div class="rounded-xl bg-green-50 p-4 text-xs font-bold text-green-700 ring-1 ring-green-100">
                    {{ session('status') }}
                </div>
            @endif

            <div class="grid grid-cols-2 gap-2 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-zinc-200">
                <div class="space-y-0.5">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Hardware ID') }}</span>
                    <p class="truncate font-mono text-[10px] font-bold text-zinc-700">{{ $deviceId }}</p>
                </div>

                <div class="space-y-0.5">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Platform') }}</span>
                    <p class="text-[11px] font-bold text-zinc-700">{{ $platformLabel }}</p>
                </div>

                <div class="space-y-0.5">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Device code') }}</span>
                    <p class="truncate text-[11px] font-bold text-zinc-700">{{ $deviceCode }}</p>
                </div>

                <div class="space-y-0.5">
                    <span class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ __('Account') }}</span>
                    <p class="truncate text-[11px] font-bold text-zinc-700">{{ $operatorIdentity }}</p>
                </div>
            </div>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form method="POST" action="{{ route('device.identity.update') }}" class="space-y-6">
                    @csrf

                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('Operator Identity') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('How this device appears in reports.') }}</p>
                        </div>

                        <button type="submit" class="rounded-xl bg-green-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition hover:bg-green-500">
                            {{ __('Save') }}
                        </button>
                    </div>

                    <div class="space-y-2">
                        <label for="operator_identity" class="text-xs font-bold text-zinc-700">{{ __('Display Name') }}</label>
                        <input
                            id="operator_identity"
                            name="operator_identity"
                            type="text"
                            value="{{ old('operator_identity', $operatorIdentity) }}"
                            placeholder="{{ __('Enter a display name') }}"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition placeholder:text-zinc-400 focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                        >
                        @error('operator_identity')
                            <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                        @enderror
                    </div>
                </form>
            </article>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form method="POST" action="{{ route('device.permissions') }}">
                    @csrf

                    <div class="flex items-center gap-3">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-indigo-50 text-indigo-700 ring-1 ring-indigo-100">
                            <svg viewBox="0 0 24 24" fill="none" aria-hidden="true" class="size-5">
                                <path d="M9 12.75 11.25 15 15 9.75" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                                <path d="M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3Z" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                            </svg>
                        </div>
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('System Permissions') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('Required for USSD automation.') }}</p>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button type="submit" class="w-full rounded-xl bg-zinc-50 px-4 py-3 text-[10px] font-black uppercase tracking-widest text-zinc-900 ring-1 ring-zinc-200 transition hover:bg-zinc-100">
                            {{ __('Grant All Permissions') }}
                        </button>
                    </div>
                </form>
            </article>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form method="POST" action="{{ route('device.hardware.update') }}" class="space-y-6">
                    @csrf

                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('SIM Slot Mapping') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('Routing rules for transactions.') }}</p>
                        </div>

                        <button type="submit" class="rounded-xl bg-green-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition hover:bg-green-500">
                            {{ __('Save Mapping') }}
                        </button>
                    </div>

                    <div class="space-y-6">
                        <fieldset class="space-y-3">
                            <legend class="text-xs font-bold text-zinc-700">{{ __('Primary Transaction SIM') }}</legend>

                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($simSlotOptions as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:border-green-300 hover:bg-green-50">
                                        <input
                                            type="radio"
                                            name="primary_transaction_sim"
                                            value="{{ $value }}"
                                            @checked(old('primary_transaction_sim', $primaryTransactionSim) === $value)
                                            class="size-4 border-zinc-300 text-green-600 focus:ring-green-500"
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @error('primary_transaction_sim')
                                <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </fieldset>

                        <fieldset class="space-y-3">
                            <legend class="text-xs font-bold text-zinc-700">{{ __('SMS Auto-Reply SIM') }}</legend>

                            <div class="grid gap-3 sm:grid-cols-2">
                                @foreach ($simSlotOptions as $value => $label)
                                    <label class="flex cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm font-semibold text-zinc-700 transition hover:border-green-300 hover:bg-green-50">
                                        <input
                                            type="radio"
                                            name="sms_auto_reply_sim"
                                            value="{{ $value }}"
                                            @checked(old('sms_auto_reply_sim', $smsAutoReplySim) === $value)
                                            class="size-4 border-zinc-300 text-green-600 focus:ring-green-500"
                                        >
                                        <span>{{ $label }}</span>
                                    </label>
                                @endforeach
                            </div>

                            @error('sms_auto_reply_sim')
                                <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </fieldset>
                    </div>
                </form>
            </article>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form method="POST" action="{{ route('device.technical.update') }}" class="space-y-6">
                    @csrf

                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <h2 class="text-sm font-bold text-zinc-900">{{ __('Automation Rules') }}</h2>
                            <p class="text-[10px] text-zinc-500">{{ __('Retry logic and USSD timeouts.') }}</p>
                        </div>

                        <button type="submit" class="rounded-xl bg-green-600 px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition hover:bg-green-500">
                            {{ __('Save Rules') }}
                        </button>
                    </div>

                    <div class="space-y-5">
                        <div class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                            <div class="flex items-center gap-3">
                                <input
                                    id="auto_reschedule_rejected"
                                    type="checkbox"
                                    name="auto_reschedule_rejected"
                                    value="1"
                                    @checked(old('auto_reschedule_rejected', $autoRescheduleRejected))
                                    class="size-4 rounded border-zinc-300 text-green-600 focus:ring-green-500"
                                >
                                <label for="auto_reschedule_rejected" class="text-xs font-bold text-zinc-900">
                                    {{ __('Auto-reschedule rejected') }}
                                </label>
                            </div>

                            <div class="mt-4 space-y-2 border-t border-zinc-200 pt-4">
                                <label for="retry_tomorrow_at" class="text-xs font-bold text-zinc-700">{{ __('Retry tomorrow at') }}</label>
                                <select
                                    id="retry_tomorrow_at"
                                    name="retry_tomorrow_at"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                                >
                                    @foreach ($retryScheduleOptions as $value => $label)
                                        <option value="{{ $value }}" @selected(old('retry_tomorrow_at', $retryTomorrowAt) === $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                                @error('retry_tomorrow_at')
                                    <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <label for="ussd_timeout_seconds" class="text-xs font-bold text-zinc-700">{{ __('USSD Timeout (s)') }}</label>
                                <input
                                    id="ussd_timeout_seconds"
                                    name="ussd_timeout_seconds"
                                    type="number"
                                    min="5"
                                    max="300"
                                    value="{{ old('ussd_timeout_seconds', $ussdTimeoutSeconds) }}"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                                >
                                @error('ussd_timeout_seconds')
                                    <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xs font-bold text-zinc-900">{{ __('Auto-Retry') }}</span>
                                    <input
                                        id="intelligent_auto_retry"
                                        type="checkbox"
                                        name="intelligent_auto_retry"
                                        value="1"
                                        @checked(old('intelligent_auto_retry', $intelligentAutoRetry))
                                        class="size-4 rounded border-zinc-300 text-green-600 focus:ring-green-500"
                                    >
                                </div>
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <label for="retry_interval_minutes" class="text-xs font-bold text-zinc-700">{{ __('Interval (min)') }}</label>
                                <input
                                    id="retry_interval_minutes"
                                    name="retry_interval_minutes"
                                    type="number"
                                    min="1"
                                    max="60"
                                    value="{{ old('retry_interval_minutes', $retryIntervalMinutes) }}"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                                >
                                @error('retry_interval_minutes')
                                    <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="space-y-2">
                                <label for="max_attempts" class="text-xs font-bold text-zinc-700">{{ __('Max Attempts') }}</label>
                                <input
                                    id="max_attempts"
                                    name="max_attempts"
                                    type="number"
                                    min="1"
                                    max="10"
                                    value="{{ old('max_attempts', $maxAttempts) }}"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                                >
                                @error('max_attempts')
                                    <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="flex items-center justify-between rounded-xl bg-zinc-50 p-4 ring-1 ring-zinc-200">
                            <span class="text-xs font-bold text-zinc-900">{{ __('Aggressive Network Recovery') }}</span>
                            <input
                                id="retry_network_issues"
                                type="checkbox"
                                name="retry_network_issues"
                                value="1"
                                @checked(old('retry_network_issues', $retryNetworkIssues))
                                class="size-4 rounded border-zinc-300 text-green-600 focus:ring-green-500"
                            >
                        </div>
                    </div>
                </form>
            </article>
        </div>
    </section>
</x-layouts::app.sidebar>
