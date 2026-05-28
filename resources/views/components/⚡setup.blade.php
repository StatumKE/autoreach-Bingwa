<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('App Setup')]
#[Layout('layouts.minimal')]
class extends Component {
    public function continueToDashboard(): void
    {
        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div
    class="relative min-h-screen pb-36"
    x-data="{
        requesting: false,
        status: {
            phoneGranted: false,
            smsGranted: false,
            contactsGranted: false,
            notificationsGranted: false,
            batteryUnrestricted: false,
            accessibilityEnabled: false,
            overlayGranted: false,
        },
        get total() {
            return 7;
        },
        get grantedCount() {
            return Object.values(this.status).filter(Boolean).length;
        },
        get allCriticalGranted() {
            return this.status.phoneGranted
                && this.status.smsGranted
                && this.status.notificationsGranted
                && this.status.batteryUnrestricted
                && this.status.accessibilityEnabled
                && this.status.overlayGranted;
        },
        async init() {
            await this.recheckStatus();
        },
        async recheckStatus() {
            const data = await this.nativeCall('CheckSetupStatus', {});

            if (! data) {
                return;
            }

            this.status.phoneGranted = data.phoneGranted ?? false;
            this.status.smsGranted = data.smsGranted ?? false;
            this.status.contactsGranted = data.contactsGranted ?? false;
            this.status.notificationsGranted = data.notificationsGranted ?? false;
            this.status.batteryUnrestricted = data.batteryUnrestricted ?? false;
            this.status.accessibilityEnabled = data.accessibilityEnabled ?? false;
            this.status.overlayGranted = data.overlayGranted ?? false;
        },
        async requestRuntimePermissions() {
            if (this.requesting) {
                return;
            }

            this.requesting = true;
            await this.nativeCall('RequestSetupPermissions', { force: true });
            await new Promise(resolve => setTimeout(resolve, 600));
            await this.recheckStatus();
            this.requesting = false;
        },
        async openBatterySettings() {
            await this.nativeCall('OpenBatterySettings', {});
        },
        async openAccessibilitySettings() {
            await this.nativeCall('OpenAccessibilitySettings', {});
        },
        async openAppInfo() {
            await this.nativeCall('OpenAppInfo', {});
        },
        async openOverlaySettings() {
            await this.nativeCall('OpenOverlaySettings', {});
        },
        async nativeCall(method, params) {
            try {
                const res = await fetch('/_native/api/call', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({ method, params }),
                });

                if (! res.ok) {
                    return null;
                }

                const envelope = await res.json();

                return envelope?.data ?? null;
            } catch {
                return null;
            }
        },
    }"
    x-init="init()"
    @visibilitychange.window="if (document.visibilityState === 'visible') recheckStatus()"
>
    {{-- Header --}}
    <div class="px-6 pt-14 pb-6">
        <div class="flex items-center justify-center mb-8">
            <div class="flex items-center gap-3 rounded-2xl bg-white/10 px-5 py-3 ring-1 ring-white/10 backdrop-blur">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-[0.25em] text-green-300">Autoreach</p>
                    <p class="text-base font-black leading-tight">Bingwa</p>
                </div>
            </div>
        </div>

        <h1 class="text-2xl font-black text-center tracking-tight">Set up your app</h1>
        <p class="text-sm text-zinc-400 text-center mt-2 max-w-xs mx-auto leading-relaxed">
            Grant the following permissions so Bingwa can automatically send data, airtime, and SMS packages to your customers.
        </p>
    </div>

    {{-- Progress bar --}}
    <div class="px-6 mb-6">
        <div class="flex items-center justify-between mb-2">
            <span class="text-[10px] font-black uppercase tracking-widest text-zinc-500">Setup Progress</span>
            <span class="text-[10px] font-black text-green-400" x-text="`${grantedCount} of ${total} granted`"></span>
        </div>
        <div class="h-1.5 w-full rounded-full bg-zinc-800 overflow-hidden">
            <div
                class="h-full rounded-full transition-all duration-700 ease-out"
                :class="allCriticalGranted ? 'bg-gradient-to-r from-green-500 to-emerald-400' : 'bg-gradient-to-r from-green-600 to-teal-500'"
                :style="`width: ${Math.max(8, Math.round((grantedCount / total) * 100))}%`"
            ></div>
        </div>
    </div>

    {{-- Permission Cards --}}
    <div class="px-4 space-y-3 pb-6">

        {{-- Phone --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.phoneGranted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.phoneGranted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.phoneGranted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 6.75Z" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Phone</span>
                        <span x-show="status.phoneGranted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Make USSD calls to send data to customers.</p>
                    <button
                        x-show="!status.phoneGranted"
                        x-cloak
                        @click="requestRuntimePermissions()"
                        :disabled="requesting"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95 disabled:opacity-50"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

        {{-- SMS --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.smsGranted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.smsGranted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.smsGranted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h7.5m-7.5 3h4.5m-10.5 6a3 3 0 0 1 3-3h11.25a3 3 0 0 1 3 3v3.75l-3.75-2.25H5.25a3 3 0 0 1-3-3V6.75a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v4.5" />
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">SMS</span>
                        <span x-show="status.smsGranted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Send auto-reply SMS and read incoming M-Pesa payment messages.</p>
                    <button
                        x-show="!status.smsGranted"
                        x-cloak
                        @click="requestRuntimePermissions()"
                        :disabled="requesting"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95 disabled:opacity-50"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

        {{-- Contacts --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.contactsGranted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.contactsGranted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.contactsGranted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Contacts</span>
                        <span x-show="status.contactsGranted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Read your device address book for customer lookup.</p>
                    <button
                        x-show="!status.contactsGranted"
                        x-cloak
                        @click="requestRuntimePermissions()"
                        :disabled="requesting"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95 disabled:opacity-50"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

        {{-- Notifications --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.notificationsGranted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.notificationsGranted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.notificationsGranted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Notifications</span>
                        <span x-show="status.notificationsGranted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Send you alerts when transactions complete or fail.</p>
                    <button
                        x-show="!status.notificationsGranted"
                        x-cloak
                        @click="requestRuntimePermissions()"
                        :disabled="requesting"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95 disabled:opacity-50"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

        {{-- Battery Unrestricted --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.batteryUnrestricted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.batteryUnrestricted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.batteryUnrestricted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Battery Unrestricted</span>
                        <span x-show="status.batteryUnrestricted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Keep Bingwa running in the background without Android killing it.</p>
                    <button
                        x-show="!status.batteryUnrestricted"
                        x-cloak
                        @click="openBatterySettings()"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

        {{-- Accessibility --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.accessibilityEnabled ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.accessibilityEnabled ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.accessibilityEnabled ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Accessibility Service</span>
                        <span x-show="status.accessibilityEnabled" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Enabled
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Reads USSD responses from the network to confirm transaction delivery.</p>
                    <div x-show="!status.accessibilityEnabled" x-cloak class="mt-3 rounded-2xl border border-amber-500/20 bg-amber-500/8 p-4">
                        <div class="text-[10px] font-black uppercase tracking-[0.24em] text-amber-300">Restricted settings</div>
                        <p class="mt-2 text-[12px] leading-relaxed text-zinc-300">
                            If Android hides this service, open app info first, allow restricted settings, then come back and enable Bingwa USSD Automation.
                        </p>
                        <ol class="mt-3 space-y-1.5 text-[11px] leading-relaxed text-zinc-400 list-decimal pl-4">
                            <li>Open the app info screen.</li>
                            <li>Tap <strong class="text-zinc-200">More</strong>.</li>
                            <li>Tap <strong class="text-zinc-200">Allow restricted settings</strong>.</li>
                            <li>Return here and enable the service.</li>
                        </ol>
                        <div class="mt-4 flex flex-col gap-2 sm:flex-row">
                            <button
                                @click="openAppInfo()"
                                class="inline-flex items-center justify-center rounded-xl bg-white px-4 py-2.5 text-[11px] font-black uppercase tracking-widest text-zinc-950 transition active:scale-95"
                            >
                                Open App Info
                            </button>
                            <button
                                @click="openAccessibilitySettings()"
                                class="inline-flex items-center justify-center rounded-xl border border-white/10 bg-zinc-900 px-4 py-2.5 text-[11px] font-black uppercase tracking-widest text-white transition active:scale-95 hover:bg-zinc-800"
                            >
                                Open Accessibility
                            </button>
                        </div>
                    </div>
                    <div x-show="!status.accessibilityEnabled" x-cloak class="mt-3 space-y-2">
                        <p class="text-[10px] text-zinc-500 leading-relaxed">
                            After you allow restricted settings in app info, return here and enable <strong class="text-zinc-300">Autoreach Bingwa</strong>.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Display Over Apps --}}
        <div
            class="rounded-2xl p-5 border transition-all duration-300"
            :class="status.overlayGranted ? 'bg-green-950/40 border-green-800/40' : 'bg-zinc-900 border-zinc-800'"
        >
            <div class="flex items-start gap-4">
                <div class="shrink-0 w-11 h-11 rounded-xl flex items-center justify-center transition-colors duration-300"
                    :class="status.overlayGranted ? 'bg-green-500/20' : 'bg-zinc-800'">
                    <svg class="w-5 h-5 transition-colors duration-300" :class="status.overlayGranted ? 'text-green-400' : 'text-zinc-400'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z"/>
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <span class="font-black text-[15px]">Display Over Apps</span>
                        <span x-show="status.overlayGranted" class="text-[10px] font-black text-green-400 uppercase tracking-widest flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Granted
                        </span>
                    </div>
                    <p class="text-[12px] text-zinc-400 leading-relaxed">Show a USSD overlay so the screen stays on while processing transactions.</p>
                    <button
                        x-show="!status.overlayGranted"
                        x-cloak
                        @click="openOverlaySettings()"
                        class="mt-3 inline-flex items-center px-4 py-2 rounded-xl bg-white text-zinc-950 text-[11px] font-black uppercase tracking-widest transition active:scale-95"
                    >
                        Grant Access
                    </button>
                </div>
            </div>
        </div>

    </div>

    {{-- Sticky footer CTA --}}
    <div class="fixed inset-x-0 bottom-0 bg-zinc-950/95 backdrop-blur-lg border-t border-zinc-800/80 px-5 py-5 z-10">
        <p
            class="text-center text-[11px] mb-3 transition-colors duration-300"
            :class="allCriticalGranted ? 'text-green-400 font-black' : 'text-zinc-500'"
            x-text="allCriticalGranted
                ? '✓ All required permissions granted — you\'re ready!'
                : 'Phone, Notifications, Battery, Accessibility and Display Over Apps are required.'"
        ></p>
        <button
            wire:click="continueToDashboard"
            class="w-full h-14 rounded-2xl font-black text-[13px] uppercase tracking-widest transition-all duration-300 active:scale-[.98]"
            :class="allCriticalGranted
                ? 'bg-green-500 text-white shadow-lg shadow-green-500/30'
                : 'bg-zinc-800 text-zinc-300 hover:bg-zinc-700'"
        >
            <span x-show="allCriticalGranted">Go to Dashboard →</span>
            <span x-show="!allCriticalGranted">Continue Anyway</span>
        </button>
    </div>

</div>
