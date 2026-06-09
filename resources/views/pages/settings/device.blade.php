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
                <form
                    id="form-device-identity"
                    method="POST"
                    action="{{ route('device.identity.update.path', ['operatorIdentity' => $operatorIdentity]) }}"
                    class="space-y-6"
                    data-identity-action-template="{{ url('/settings/device/identity/__OPERATOR_IDENTITY__') }}"
                    oninput="
                        const val = this.elements.operator_identity?.value || '';
                        this.action = this.dataset.identityActionTemplate
                            .replace('__OPERATOR_IDENTITY__', encodeURIComponent(val));
                    "
                    onsubmit="
                        const val = this.elements.operator_identity?.value || '';
                        this.action = this.dataset.identityActionTemplate
                            .replace('__OPERATOR_IDENTITY__', encodeURIComponent(val));
                    "
                >
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
                <form
                    id="form-device-hardware"
                    method="POST"
                    action="{{ route('device.hardware.update.path', [
                        'primaryTransactionSim' => $primaryTransactionSim,
                        'smsAutoReplySim' => $smsAutoReplySim,
                    ]) }}"
                    class="space-y-6"
                    data-hardware-action-template="{{ url('/settings/device/hardware/__PRIMARY_SIM__/__SMS_SIM__') }}"
                    onchange="
                        const primary = this.elements.primary_transaction_sim?.value || 'slot_1';
                        const sms = this.elements.sms_auto_reply_sim?.value || 'slot_1';
                        this.action = this.dataset.hardwareActionTemplate
                            .replace('__PRIMARY_SIM__', encodeURIComponent(primary))
                            .replace('__SMS_SIM__', encodeURIComponent(sms));
                    "
                    onsubmit="
                        const primary = this.elements.primary_transaction_sim?.value || 'slot_1';
                        const sms = this.elements.sms_auto_reply_sim?.value || 'slot_1';
                        this.action = this.dataset.hardwareActionTemplate
                            .replace('__PRIMARY_SIM__', encodeURIComponent(primary))
                            .replace('__SMS_SIM__', encodeURIComponent(sms));
                    "
                >
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

                    <div class="space-y-5">
                        <div class="space-y-2">
                            <label for="primary_transaction_sim" class="text-xs font-bold text-zinc-700">{{ __('Primary Transaction SIM') }}</label>

                            <select
                                id="primary_transaction_sim"
                                name="primary_transaction_sim"
                                class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm font-bold text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                            >
                                @foreach ($simSlotOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($primaryTransactionSim === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('primary_transaction_sim')
                                <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="space-y-2">
                            <label for="sms_auto_reply_sim" class="text-xs font-bold text-zinc-700">{{ __('SMS Auto-Reply SIM') }}</label>

                            <select
                                id="sms_auto_reply_sim"
                                name="sms_auto_reply_sim"
                                class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm font-bold text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                            >
                                @foreach ($simSlotOptions as $value => $label)
                                    <option value="{{ $value }}" @selected($smsAutoReplySim === $value)>
                                        {{ $label }}
                                    </option>
                                @endforeach
                            </select>

                            @error('sms_auto_reply_sim')
                                <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </form>
            </article>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form id="form-device-processing" method="POST" action="{{ route('device.processing.toggle') }}" class="space-y-6">
                    @csrf

                    <div class="space-y-1">
                        <h2 class="text-sm font-bold text-zinc-900">{{ __('Transaction Processing') }}</h2>
                        <p class="text-[10px] text-zinc-500">{{ __('Pause the processor without deleting queued transactions.') }}</p>
                    </div>

                    <div @class([
                        'rounded-xl p-4 ring-1',
                        'bg-rose-50 ring-rose-100' => $transactionProcessingEnabled,
                        'bg-green-50 ring-green-100' => ! $transactionProcessingEnabled,
                    ])>
                        <div class="flex items-center justify-between gap-3">
                            <div @class([
                                'text-[10px] font-bold uppercase tracking-widest',
                                'text-rose-700' => $transactionProcessingEnabled,
                                'text-green-700' => ! $transactionProcessingEnabled,
                            ])>
                                {{ $transactionProcessingEnabled ? __('Processor is active') : __('Processor is paused') }}
                            </div>

                            <span @class([
                                'rounded-full px-3 py-1 text-[9px] font-black uppercase tracking-widest ring-1',
                                'bg-rose-100 text-rose-700 ring-rose-200' => $transactionProcessingEnabled,
                                'bg-green-100 text-green-700 ring-green-200' => ! $transactionProcessingEnabled,
                            ])>
                                {{ $transactionProcessingEnabled ? __('Active') : __('Paused') }}
                            </span>
                        </div>
                        <p class="mt-2 text-[11px] leading-relaxed text-zinc-600">
                            {{ $transactionProcessingEnabled
                                ? __('New transactions will be processed as soon as they are queued.')
                                : __('New transactions stay queued in the database until you resume processing.') }}
                        </p>
                    </div>

                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-[10px] leading-relaxed text-zinc-500">
                                {{ $transactionProcessingEnabled
                                    ? __('Tap to pause new transaction processing. Queued transactions remain in the database.')
                                    : __('Tap to resume processing. The oldest queued transactions will continue first.') }}
                            </p>
                        </div>

                        <flux:modal.trigger name="device-processing-modal">
                            <button
                                type="button"
                                @class([
                                    'shrink-0 rounded-xl px-4 py-2 text-xs font-black uppercase tracking-wider text-white transition shadow-sm',
                                    'bg-rose-600 hover:bg-rose-500' => $transactionProcessingEnabled,
                                    'bg-green-600 hover:bg-green-500' => ! $transactionProcessingEnabled,
                                ])
                            >
                                {{ $transactionProcessingEnabled ? __('Disable Processing') : __('Resume Processing') }}
                            </button>
                        </flux:modal.trigger>
                    </div>
                </form>

                <flux:modal name="device-processing-modal" class="max-w-md">
                    <div class="space-y-6">
                        <div>
                            <flux:heading size="lg">
                                {{ $transactionProcessingEnabled ? __('Pause Transaction Processing?') : __('Activate Transaction Processing?') }}
                            </flux:heading>
                            <flux:text class="mt-2 text-sm text-zinc-500">
                                {{ $transactionProcessingEnabled
                                    ? __('Pausing transaction processing will stop the app from automatically fulfilling incoming SMS or queuing USSD codes. Incoming requests will be kept in the queue until you activate processing again.')
                                    : __('Activating transaction processing will allow the app to resume automatically fulfilling incoming requests via USSD.') }}
                            </flux:text>
                        </div>

                        <div class="flex justify-end gap-2">
                            <flux:modal.close>
                                <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                            </flux:modal.close>
                            
                            <flux:button
                                type="submit"
                                form="form-device-processing"
                                variant="{{ $transactionProcessingEnabled ? 'danger' : 'primary' }}"
                            >
                                {{ $transactionProcessingEnabled ? __('Pause Processing') : __('Activate Processing') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:modal>
            </article>

            <article class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-zinc-200">
                <form id="form-device-technical" method="POST" action="{{ route('device.technical.update') }}" class="space-y-6">
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
                            <div class="space-y-1">
                                <h3 class="text-xs font-bold text-zinc-900">{{ __('Incoming M-Pesa SMS') }}</h3>
                                <p class="text-[10px] leading-relaxed text-zinc-500">
                                    {{ __('Create local transactions from received M-Pesa payment messages.') }}
                                </p>
                            </div>

                            <div class="mt-4 space-y-4 border-t border-zinc-200 pt-4">
                                <label for="incoming_sms_enabled" class="flex items-center justify-between gap-4">
                                    <span class="text-xs font-bold text-zinc-900">{{ __('Read incoming M-Pesa SMS') }}</span>
                                    <input
                                        id="incoming_sms_enabled"
                                        type="checkbox"
                                        name="incoming_sms_enabled"
                                        value="1"
                                        @checked(old('incoming_sms_enabled', $incomingSmsEnabled))
                                        class="size-4 rounded border-zinc-300 text-green-600 focus:ring-green-500"
                                    >
                                </label>

                                <label for="incoming_sms_allow_all_senders" class="flex items-center justify-between gap-4">
                                    <span class="min-w-0">
                                        <span class="block text-xs font-bold text-zinc-900">{{ __('Allow all SMS senders') }}</span>
                                        <span class="block text-[10px] leading-relaxed text-zinc-500">{{ __('Default only trusts MPESA and M-PESA sender IDs.') }}</span>
                                    </span>
                                    <input
                                        id="incoming_sms_allow_all_senders"
                                        type="checkbox"
                                        name="incoming_sms_allow_all_senders"
                                        value="1"
                                        @checked(old('incoming_sms_allow_all_senders', $incomingSmsAllowAllSenders))
                                        class="size-4 rounded border-zinc-300 text-green-600 focus:ring-green-500"
                                    >
                                </label>

                                <div class="space-y-2">
                                    <label for="incoming_sms_sim_slot" class="text-xs font-bold text-zinc-700">{{ __('Read SMS from') }}</label>
                                    <select
                                        id="incoming_sms_sim_slot"
                                        name="incoming_sms_sim_slot"
                                        class="w-full rounded-xl border border-zinc-200 bg-white px-4 py-3 text-sm text-zinc-900 shadow-sm outline-none transition focus:border-green-500 focus:ring-2 focus:ring-green-500/20"
                                    >
                                        @foreach ($incomingSmsSlotOptions as $value => $label)
                                            <option value="{{ $value }}" @selected(old('incoming_sms_sim_slot', $incomingSmsSimSlot) === $value)>
                                                {{ $label }}
                                            </option>
                                        @endforeach
                                    </select>
                                    @error('incoming_sms_sim_slot')
                                        <p class="text-xs font-semibold text-rose-600">{{ $message }}</p>
                                    @enderror
                                </div>
                            </div>
                        </div>

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
