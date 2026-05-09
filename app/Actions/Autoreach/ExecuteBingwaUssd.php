<?php

namespace App\Actions\Autoreach;

use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteBingwaUssd
{
    /**
     * Execute a queued Bingwa transaction through the native USSD bridge.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string, raw_response: string|null}
     */
    public function execute(array $payload, ?string $flowId = null): array
    {
        $code = trim((string) ($payload['code'] ?? ''));
        $mode = (string) ($payload['mode'] ?? 'express');
        $simSlot = (int) ($payload['sim_slot'] ?? $payload['simSlot'] ?? 0);
        $isSambaza = (bool) ($payload['is_sambaza'] ?? $payload['isSambaza'] ?? false);

        if ($code === '') {
            Log::warning('Bingwa USSD execution skipped because the code was empty.', [
                'flow_id' => $flowId,
                'transaction_id' => $payload['backend_transaction_id'] ?? null,
                'id' => $payload['id'] ?? null,
            ]);

            return [
                'success' => false,
                'message' => __('USSD code was empty.'),
                'raw_response' => null,
            ];
        }

        if (! function_exists('nativephp_call')) {
            Log::warning('Bingwa USSD execution skipped because the native bridge is unavailable.', [
                'flow_id' => $flowId,
                'transaction_id' => $payload['backend_transaction_id'] ?? null,
                'id' => $payload['id'] ?? null,
            ]);

            return [
                'success' => false,
                'message' => __('Native USSD bridge is unavailable.'),
                'raw_response' => null,
            ];
        }

        $bridgePayload = json_encode([
            'code' => $code,
            'mode' => $mode,
            'simSlot' => $simSlot,
            'isSambaza' => $isSambaza,
        ]);

        Log::info("📡 [BRIDGE DISPATCH] Sending USSD to Android Hardware", [
            'transaction_id' => $payload['id'] ?? null,
            'code' => $code,
            'sim_slot' => $simSlot,
            'mode' => $mode,
        ]);

        try {
            $rawResponse = nativephp_call('ExecuteUssd', $bridgePayload);
        } catch (Throwable $throwable) {
            Log::warning('Bingwa USSD execution threw an exception.', [
                'flow_id' => $flowId,
                'transaction_id' => $payload['backend_transaction_id'] ?? null,
                'id' => $payload['id'] ?? null,
                'message' => $throwable->getMessage(),
            ]);

            report($throwable);

            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'raw_response' => null,
            ];
        }

        Log::debug('Bingwa USSD execution raw response received.', [
            'flow_id' => $flowId,
            'transaction_id' => $payload['backend_transaction_id'] ?? null,
            'id' => $payload['id'] ?? null,
            'response' => $rawResponse,
        ]);

        if (! is_string($rawResponse) || $rawResponse === '') {
            return [
                'success' => false,
                'message' => __('The native USSD bridge returned no response.'),
                'raw_response' => null,
            ];
        }

        $decoded = json_decode($rawResponse, true);

        if (! is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('The native USSD bridge returned an invalid response.'),
                'raw_response' => $rawResponse,
            ];
        }

        $bridgeError = $decoded['error'] ?? $decoded['message'] ?? null;

        if (is_string($bridgeError) && $bridgeError !== '' && ($decoded['data'] ?? null) === null) {
            return [
                'success' => false,
                'message' => $bridgeError,
                'raw_response' => $rawResponse,
            ];
        }

        $nativeData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $success = (bool) ($nativeData['success'] ?? false);
        $message = (string) ($nativeData['message'] ?? $decoded['message'] ?? '');

        if ($message === '') {
            $message = $success
                ? __('USSD request completed.')
                : __('USSD request failed.');
        }

        Log::debug('Bingwa USSD execution processed.', [
            'flow_id' => $flowId,
            'transaction_id' => $payload['backend_transaction_id'] ?? null,
            'id' => $payload['id'] ?? null,
            'success' => $success,
            'message' => $message,
        ]);

        return [
            'success' => $success,
            'message' => $message,
            'raw_response' => $rawResponse,
        ];
    }
}
