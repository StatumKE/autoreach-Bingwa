<?php

namespace App\Http\Controllers\Settings;

use App\Models\DeviceSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceSettingsController extends BaseController
{
    public function edit(): View
    {
        return view('pages.settings.device', $this->viewData());
    }

    public function updateIdentity(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'operator_identity' => ['required', 'string', 'max:120'],
        ]);

        $this->persist([
            'operator_identity' => $validated['operator_identity'],
        ]);

        return redirect()
            ->route('device.edit')
            ->with('status', __('Operator identity updated.'));
    }

    public function updateHardware(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'primary_transaction_sim' => ['required', Rule::in(array_keys($this->simSlotOptions()))],
            'sms_auto_reply_sim' => ['required', Rule::in(array_keys($this->simSlotOptions()))],
        ]);

        $this->persist([
            'primary_transaction_sim' => $validated['primary_transaction_sim'],
            'sms_auto_reply_sim' => $validated['sms_auto_reply_sim'],
        ]);

        return redirect()
            ->route('device.edit')
            ->with('status', __('SIM mapping updated.'));
    }

    public function updateTechnical(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'auto_reschedule_rejected' => ['sometimes', 'boolean'],
            'retry_tomorrow_at' => ['nullable', Rule::in(array_keys($this->retryScheduleOptions()))],
            'ussd_timeout_seconds' => ['required', 'integer', 'min:5', 'max:300'],
            'intelligent_auto_retry' => ['sometimes', 'boolean'],
            'retry_interval_minutes' => ['required', 'integer', 'min:1', 'max:60'],
            'max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'retry_network_issues' => ['sometimes', 'boolean'],
        ]);

        $autoRescheduleRejected = $request->boolean('auto_reschedule_rejected');
        $intelligentAutoRetry = $request->boolean('intelligent_auto_retry');
        $retryNetworkIssues = $request->boolean('retry_network_issues');

        $this->persist([
            'auto_reschedule_rejected' => $autoRescheduleRejected,
            'retry_tomorrow_at' => $autoRescheduleRejected ? ($validated['retry_tomorrow_at'] ?? null) : null,
            'ussd_timeout_seconds' => (int) $validated['ussd_timeout_seconds'],
            'intelligent_auto_retry' => $intelligentAutoRetry,
            'retry_interval_minutes' => (int) $validated['retry_interval_minutes'],
            'max_attempts' => (int) $validated['max_attempts'],
            'retry_network_issues' => $retryNetworkIssues,
        ]);

        return redirect()
            ->route('device.edit')
            ->with('status', __('Technical settings updated.'));
    }

    public function requestPermissions(): RedirectResponse
    {
        if (! function_exists('nativephp_call')) {
            return redirect()
                ->route('device.edit')
                ->with('status', __('Native features unavailable.'));
        }

        try {
            nativephp_call('RequestSetupPermissions', json_encode([
                'force' => true,
                'openSpecialSettings' => true,
            ], JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return redirect()
                ->route('device.edit')
                ->with('status', __('Unable to request permissions right now.'));
        }

        return redirect()
            ->route('device.edit')
            ->with('status', __('Requesting permissions…'));
    }

    /**
     * @return array{
     *     deviceId: string,
     *     deviceCode: string,
     *     platformLabel: string,
     *     operatorIdentity: string,
     *     primaryTransactionSim: string,
     *     smsAutoReplySim: string,
     *     autoRescheduleRejected: bool,
     *     retryTomorrowAt: string,
     *     ussdTimeoutSeconds: string,
     *     intelligentAutoRetry: bool,
     *     retryIntervalMinutes: string,
     *     maxAttempts: string,
     *     retryNetworkIssues: bool,
     *     simSlotOptions: array<string, string>,
     *     retryScheduleOptions: array<string, string>,
     * }
     */
    private function viewData(): array
    {
        $user = Auth::user();
        $deviceSetting = $user?->deviceSetting;
        $registration = $user?->bingwaDeviceRegistration;

        $deviceId = $registration?->hardware_id ?? $user?->autoreach_connect_id ?? 'Unknown';
        $deviceCode = $registration?->bhc_code ?? 'None';
        $osName = 'Android';
        $osVersion = 'Unknown';

        if (function_exists('nativephp_call')) {
            try {
                $idResponse = json_decode(nativephp_call('Device.GetId', '{}'), true, flags: JSON_THROW_ON_ERROR);

                if (isset($idResponse['data']['id'])) {
                    $deviceId = (string) $idResponse['data']['id'];
                }

                $infoResponse = json_decode(nativephp_call('Device.GetInfo', '{}'), true, flags: JSON_THROW_ON_ERROR);

                if (isset($infoResponse['data']['os_name'])) {
                    $osName = (string) $infoResponse['data']['os_name'];
                }

                if (isset($infoResponse['data']['os_version'])) {
                    $osVersion = (string) $infoResponse['data']['os_version'];
                }
            } catch (\Throwable) {
                // Fall back to the stored registration details.
            }
        }

        return [
            'deviceId' => $deviceId,
            'deviceCode' => $deviceCode,
            'platformLabel' => $osVersion !== 'Unknown' ? trim("{$osName} {$osVersion}") : ($osName ?: __('Android')),
            'operatorIdentity' => $deviceSetting?->operator_identity ?? $user?->name ?? '',
            'primaryTransactionSim' => $deviceSetting?->primary_transaction_sim ?? 'slot_1',
            'smsAutoReplySim' => $deviceSetting?->sms_auto_reply_sim ?? 'slot_1',
            'autoRescheduleRejected' => (bool) ($deviceSetting?->auto_reschedule_rejected ?? false),
            'retryTomorrowAt' => $deviceSetting?->retry_tomorrow_at ?? '12:30 AM',
            'ussdTimeoutSeconds' => (string) ($deviceSetting?->ussd_timeout_seconds ?? 30),
            'intelligentAutoRetry' => (bool) ($deviceSetting?->intelligent_auto_retry ?? true),
            'retryIntervalMinutes' => (string) ($deviceSetting?->retry_interval_minutes ?? 1),
            'maxAttempts' => (string) ($deviceSetting?->max_attempts ?? 2),
            'retryNetworkIssues' => (bool) ($deviceSetting?->retry_network_issues ?? false),
            'simSlotOptions' => $this->simSlotOptions(),
            'retryScheduleOptions' => $this->retryScheduleOptions(),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function simSlotOptions(): array
    {
        return [
            'slot_1' => __('Slot 1'),
            'slot_2' => __('Slot 2'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function retryScheduleOptions(): array
    {
        $options = [];

        for ($hour = 0; $hour < 24; $hour++) {
            foreach ([0, 30] as $minute) {
                $time = now()->copy()->startOfDay()->addHours($hour)->addMinutes($minute)->format('g:i A');
                $options[$time] = $time;
            }
        }

        return $options;
    }

    /**
     * @param  array<string, bool|int|string|null>  $data
     */
    private function persist(array $data): void
    {
        $userId = Auth::id();

        if (! $userId) {
            return;
        }

        DeviceSetting::query()->updateOrCreate(['user_id' => $userId], $data);
    }
}
