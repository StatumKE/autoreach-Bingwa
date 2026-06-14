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
    class="relative min-h-screen overflow-hidden bg-[#050807] px-4 pb-36 pt-5 text-white"
    x-data="{
        requesting: false,
        status: {
            phoneGranted: false,
            smsGranted: false,
            notificationsGranted: false,
            batteryUnrestricted: false,
            accessibilityEnabled: false,
            overlayGranted: false,
        },
        get total() {
            return 6;
        },
        get grantedCount() {
            return Object.values(this.status).filter(Boolean).length;
        },
        get progressPercent() {
            return Math.max(8, Math.round((this.grantedCount / this.total) * 100));
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
    <div class="absolute inset-x-0 top-0 -z-10 h-64 bg-[radial-gradient(circle_at_top,_rgba(34,197,94,0.28),_transparent_58%)]"></div>
    <div class="absolute left-1/2 top-20 -z-10 h-56 w-56 -translate-x-1/2 rounded-full bg-emerald-500/10 blur-3xl"></div>
    <div class="absolute right-0 top-44 -z-10 h-44 w-44 rounded-full bg-lime-500/10 blur-3xl"></div>

    <div class="mx-auto flex max-w-3xl flex-col gap-4">
        <section class="relative overflow-hidden rounded-[2rem] border border-white/10 bg-white/[0.04] px-5 py-5 shadow-2xl shadow-black/30 ring-1 ring-white/5 backdrop-blur-xl">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex items-center gap-2 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-3 py-1.5">
                    <div class="h-2 w-2 rounded-full bg-emerald-400 shadow-[0_0_24px_rgba(74,222,128,0.85)]"></div>
                    <div>
                        <p class="text-[8px] font-black uppercase tracking-[0.28em] text-emerald-200/80">Autoreach</p>
                        <p class="text-xs font-black leading-tight text-white">Bingwa</p>
                    </div>
                </div>

                <div class="rounded-full border border-white/10 bg-black/20 px-3 py-1 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-300">
                    Setup
                </div>
            </div>

            <div class="mt-5 space-y-3">
                <div class="space-y-1">
                    <h1 class="text-2xl font-black tracking-tight text-white sm:text-3xl">Set up your phone</h1>
                    <p class="max-w-2xl text-sm leading-6 text-zinc-300">
                        Grant the permissions Bingwa needs to run USSD flows, read the response, and keep transaction processing reliable in the background.
                    </p>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.26em] text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">Target</p>
                        <p class="mt-1 text-sm font-black text-white">Android 13+</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.26em] text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">Key step</p>
                        <p class="mt-1 text-sm font-black text-white">Accessibility access</p>
                    </div>
                    <div class="rounded-2xl border border-white/10 bg-black/20 px-4 py-3">
                        <p class="text-[9px] font-black uppercase tracking-[0.26em] text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">Samsung</p>
                        <p class="mt-1 text-sm font-black text-white">Allow restricted settings</p>
                    </div>
                </div>
            </div>
        </section>

        <section class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-[9px] font-black uppercase tracking-[0.28em] text-zinc-500 dark:text-zinc-400 dark:text-zinc-500">Setup progress</p>
                    <p class="mt-1 text-sm font-black text-white"><span x-text="grantedCount"></span> of <span x-text="total"></span> permissions ready</p>
                </div>
                <div class="rounded-full border border-emerald-400/15 bg-emerald-400/10 px-3 py-1 text-[10px] font-black uppercase tracking-[0.24em] text-emerald-200" x-text="allCriticalGranted ? 'Ready' : 'In progress'"></div>
            </div>

            <div class="mt-3 h-2 overflow-hidden rounded-full bg-white/8">
                <div
                    class="h-full rounded-full bg-gradient-to-r from-emerald-400 via-lime-400 to-green-400 transition-all duration-700 ease-out"
                    :style="`width: ${progressPercent}%`"
                ></div>
            </div>

            <p
                class="mt-3 text-xs leading-5 text-zinc-400 dark:text-zinc-500"
                x-text="allCriticalGranted
                    ? 'Core permissions are ready. You can continue.'
                    : 'Complete the cards below. The accessibility step is the one that usually requires the extra Settings tap on Samsung.'"
            ></p>
        </section>

        <section class="rounded-[2rem] border border-emerald-400/15 bg-gradient-to-br from-emerald-400/10 via-white/[0.04] to-white/[0.02] p-4 shadow-xl shadow-black/20 ring-1 ring-emerald-400/10">
            <div class="flex items-start gap-3">
                <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-emerald-400/20 bg-emerald-400/10">
                    <svg class="size-5 text-emerald-200" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17 17.25 21A2.652 2.652 0 0 0 21 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 1 1-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 0 0 4.486-6.336l-3.276 3.277a3.004 3.004 0 0 1-2.25-2.25l3.276-3.276a4.5 4.5 0 0 0-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437 1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008Z"/>
                    </svg>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-sm font-black text-white">Accessibility service</h2>
                        <span x-show="status.accessibilityEnabled" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                            Enabled
                        </span>
                        <span x-show="!status.accessibilityEnabled" x-cloak class="inline-flex items-center gap-1 rounded-full border border-amber-400/20 bg-amber-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-amber-200">
                            Needs setup
                        </span>
                    </div>

                    <p class="mt-1 max-w-2xl text-sm leading-6 text-zinc-300">
                        Bingwa uses accessibility to read USSD responses after the network replies, so it can confirm payment and delivery status.
                    </p>

                    <div class="mt-3 flex flex-col gap-2 sm:flex-row">
                        <button
                            x-show="!status.accessibilityEnabled"
                            x-cloak
                            @click="openAccessibilitySettings()"
                            class="inline-flex h-11 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition active:scale-[0.98]"
                        >
                            Open Accessibility
                        </button>

                        <button
                            x-show="!status.accessibilityEnabled"
                            x-cloak
                            @click="openAppInfo()"
                            class="inline-flex h-11 items-center justify-center rounded-2xl border border-white/10 bg-white/5 px-4 text-[10px] font-black uppercase tracking-[0.24em] text-white transition hover:bg-white/10 active:scale-[0.98]"
                        >
                            Open App Info
                        </button>
                    </div>

                    <div x-show="!status.accessibilityEnabled" x-cloak class="mt-4 grid gap-3 sm:grid-cols-2">
                        <div class="rounded-2xl border border-white/10 bg-black/20 p-4">
                            <p class="text-[9px] font-black uppercase tracking-[0.26em] text-emerald-200/80">After you open Accessibility</p>
                            <ul class="mt-2 space-y-2 text-xs leading-5 text-zinc-300">
                                <li class="flex gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-300"></span>
                                    <span><strong class="font-black text-white">Samsung:</strong> tap <strong class="font-black text-white">Installed apps</strong>.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-300"></span>
                                    <span><strong class="font-black text-white">Other Android phones:</strong> look for <strong class="font-black text-white">Downloaded services</strong>, <strong class="font-black text-white">Installed services</strong>, or a similar list.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-emerald-300"></span>
                                    <span>Turn on <strong class="font-black text-white">Bingwa USSD Automation</strong>.</span>
                                </li>
                            </ul>
                        </div>

                        <div class="rounded-2xl border border-amber-400/20 bg-amber-400/10 p-4">
                            <p class="text-[9px] font-black uppercase tracking-[0.26em] text-amber-200">If Android says restricted setting</p>
                            <ol class="mt-2 space-y-2 text-xs leading-5 text-zinc-200/90">
                                <li class="flex gap-2">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-[9px] font-black text-white">1</span>
                                    <span>Open <strong class="font-black text-white">App Info</strong>.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-[9px] font-black text-white">2</span>
                                    <span>Tap <strong class="font-black text-white">More</strong> in the top-right corner.</span>
                                </li>
                                <li class="flex gap-2">
                                    <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-white/10 text-[9px] font-black text-white">3</span>
                                    <span>Choose <strong class="font-black text-white">Allow restricted settings</strong>, then return here.</span>
                                </li>
                            </ol>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-3 md:grid-cols-2">
            <section
                class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl transition-all duration-300"
                :class="status.phoneGranted ? 'border-emerald-400/15 bg-emerald-400/8' : ''"
            >
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20"
                        :class="status.phoneGranted ? 'border-emerald-400/20 bg-emerald-400/10' : ''">
                        <svg class="size-5" :class="status.phoneGranted ? 'text-emerald-200' : 'text-zinc-400 dark:text-zinc-500'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 0 0 2.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 0 1-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 0 0-1.091-.852H4.5A2.25 2.25 0 0 0 2.25 6.75Z" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-black text-white">Phone</h3>
                            <span x-show="status.phoneGranted" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                                Granted
                            </span>
                        </div>

                        <p class="mt-1 text-sm leading-6 text-zinc-300">Make USSD calls so Bingwa can send transaction data and receive the network response.</p>

                        <button
                            x-show="!status.phoneGranted"
                            x-cloak
                            @click="requestRuntimePermissions()"
                            :disabled="requesting"
                            class="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Grant Access
                        </button>
                    </div>
                </div>
            </section>

            <section
                class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl transition-all duration-300"
                :class="status.smsGranted ? 'border-emerald-400/15 bg-emerald-400/8' : ''"
            >
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20"
                        :class="status.smsGranted ? 'border-emerald-400/20 bg-emerald-400/10' : ''">
                        <svg class="size-5" :class="status.smsGranted ? 'text-emerald-200' : 'text-zinc-400 dark:text-zinc-500'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 6.75h7.5m-7.5 3h4.5m-10.5 6a3 3 0 0 1 3-3h11.25a3 3 0 0 1 3 3v3.75l-3.75-2.25H5.25a3 3 0 0 1-3-3V6.75a3 3 0 0 1 3-3h13.5a3 3 0 0 1 3 3v4.5" />
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-black text-white">SMS</h3>
                            <span x-show="status.smsGranted" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                                Granted
                            </span>
                        </div>

                        <p class="mt-1 text-sm leading-6 text-zinc-300">Read incoming payment messages so Bingwa can detect customer deposits instantly.</p>

                        <button
                            x-show="!status.smsGranted"
                            x-cloak
                            @click="requestRuntimePermissions()"
                            :disabled="requesting"
                            class="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Grant Access
                        </button>
                    </div>
                </div>
            </section>

            <section
                class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl transition-all duration-300"
                :class="status.notificationsGranted ? 'border-emerald-400/15 bg-emerald-400/8' : ''"
            >
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20"
                        :class="status.notificationsGranted ? 'border-emerald-400/20 bg-emerald-400/10' : ''">
                        <svg class="size-5" :class="status.notificationsGranted ? 'text-emerald-200' : 'text-zinc-400 dark:text-zinc-500'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-black text-white">Notifications</h3>
                            <span x-show="status.notificationsGranted" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                                Granted
                            </span>
                        </div>

                        <p class="mt-1 text-sm leading-6 text-zinc-300">Send alerts when transactions complete or fail.</p>

                        <button
                            x-show="!status.notificationsGranted"
                            x-cloak
                            @click="requestRuntimePermissions()"
                            :disabled="requesting"
                            class="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition disabled:cursor-not-allowed disabled:opacity-50"
                        >
                            Grant Access
                        </button>
                    </div>
                </div>
            </section>

            <section
                class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl transition-all duration-300"
                :class="status.batteryUnrestricted ? 'border-emerald-400/15 bg-emerald-400/8' : ''"
            >
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20"
                        :class="status.batteryUnrestricted ? 'border-emerald-400/20 bg-emerald-400/10' : ''">
                        <svg class="size-5" :class="status.batteryUnrestricted ? 'text-emerald-200' : 'text-zinc-400 dark:text-zinc-500'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="m3.75 13.5 10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75Z"/>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-black text-white">Battery unrestricted</h3>
                            <span x-show="status.batteryUnrestricted" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                                Granted
                            </span>
                        </div>

                        <p class="mt-1 text-sm leading-6 text-zinc-300">Keep Bingwa alive in the background so Android does not stop it early.</p>

                        <button
                            x-show="!status.batteryUnrestricted"
                            x-cloak
                            @click="openBatterySettings()"
                            class="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition active:scale-[0.98]"
                        >
                            Grant Access
                        </button>
                    </div>
                </div>
            </section>

            <section
                class="rounded-[2rem] border border-white/10 bg-white/[0.04] p-4 shadow-xl shadow-black/20 ring-1 ring-white/5 backdrop-blur-xl transition-all duration-300"
                :class="status.overlayGranted ? 'border-emerald-400/15 bg-emerald-400/8' : ''"
            >
                <div class="flex items-start gap-3">
                    <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-white/10 bg-black/20"
                        :class="status.overlayGranted ? 'border-emerald-400/20 bg-emerald-400/10' : ''">
                        <svg class="size-5" :class="status.overlayGranted ? 'text-emerald-200' : 'text-zinc-400 dark:text-zinc-500'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 7.125C2.25 6.504 2.754 6 3.375 6h6c.621 0 1.125.504 1.125 1.125v3.75c0 .621-.504 1.125-1.125 1.125h-6a1.125 1.125 0 0 1-1.125-1.125v-3.75ZM14.25 8.625c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v8.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-8.25ZM3.75 16.125c0-.621.504-1.125 1.125-1.125h5.25c.621 0 1.125.504 1.125 1.125v2.25c0 .621-.504 1.125-1.125 1.125h-5.25a1.125 1.125 0 0 1-1.125-1.125v-2.25Z"/>
                        </svg>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-sm font-black text-white">Display over apps</h3>
                            <span x-show="status.overlayGranted" class="inline-flex items-center gap-1 rounded-full border border-emerald-400/15 bg-emerald-400/10 px-2.5 py-1 text-[9px] font-black uppercase tracking-[0.24em] text-emerald-200">
                                Granted
                            </span>
                        </div>

                        <p class="mt-1 text-sm leading-6 text-zinc-300">Show the USSD overlay and keep the screen awake while Bingwa waits for a reply.</p>

                        <button
                            x-show="!status.overlayGranted"
                            x-cloak
                            @click="openOverlaySettings()"
                            class="mt-3 inline-flex h-10 items-center justify-center rounded-2xl bg-white px-4 text-[10px] font-black uppercase tracking-[0.24em] text-zinc-950 transition active:scale-[0.98]"
                        >
                            Grant Access
                        </button>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div class="fixed inset-x-0 bottom-0 z-10 border-t border-white/10 bg-[#050807]/96 px-4 py-4 backdrop-blur-xl">
        <div class="mx-auto flex max-w-3xl flex-col gap-3">
            <p
                class="text-center text-xs leading-5 transition-colors duration-300"
                :class="allCriticalGranted ? 'font-black text-emerald-300' : 'text-zinc-400 dark:text-zinc-500'"
                x-text="allCriticalGranted
                    ? 'All required permissions are ready. You can continue.'
                    : 'Complete the cards above. If Accessibility is blocked, use App Info first, then Allow restricted settings.'"
            ></p>

            <button
                wire:click="continueToDashboard"
                class="inline-flex h-14 w-full items-center justify-center rounded-2xl border border-white/10 text-[12px] font-black uppercase tracking-[0.26em] transition active:scale-[0.99]"
                :class="allCriticalGranted
                    ? 'bg-gradient-to-r from-emerald-400 to-lime-400 text-zinc-950 shadow-lg shadow-emerald-400/25'
                    : 'bg-white/5 text-white hover:bg-white/10'"
            >
                <span x-show="allCriticalGranted">Go to Dashboard -></span>
                <span x-show="!allCriticalGranted" x-cloak>Continue Anyway</span>
            </button>
        </div>
    </div>
</div>
