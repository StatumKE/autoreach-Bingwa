<?php

namespace App\Actions\Autoreach;

use App\Exceptions\UssdModemBusyException;
use App\Support\BingwaUssdResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

class ExecuteBingwaUssd
{
    public function __construct(private UssdModemLock $ussdModemLock) {}

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
        $timeoutSeconds = max(1, (int) ($payload['timeout'] ?? $payload['timeoutSeconds'] ?? 30));

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
            'timeoutSeconds' => $timeoutSeconds,
        ]);

        Log::info('📡 [BRIDGE DISPATCH] Sending USSD to Android Hardware', [
            'transaction_id' => $payload['id'] ?? null,
            'code' => $code,
            'sim_slot' => $simSlot,
            'mode' => $mode,
            'timeout_seconds' => $timeoutSeconds,
        ]);

        try {
            $rawResponse = $this->ussdModemLock->run(
                callback: fn (): mixed => nativephp_call('ExecuteUssd', $bridgePayload),
                operation: 'queued-transaction',
                waitSeconds: 2,
                leaseSeconds: $timeoutSeconds + 15,
                context: [
                    'flow_id' => $flowId,
                    'transaction_id' => $payload['backend_transaction_id'] ?? null,
                    'id' => $payload['id'] ?? null,
                    'mode' => $mode,
                    'sim_slot' => $simSlot,
                ],
            );
        } catch (UssdModemBusyException $exception) {
            Log::info('Bingwa USSD execution skipped because the modem is busy.', [
                'flow_id' => $flowId,
                'transaction_id' => $payload['backend_transaction_id'] ?? null,
                'id' => $payload['id'] ?? null,
                'mode' => $mode,
                'sim_slot' => $simSlot,
            ]);

            return [
                'success' => false,
                'message' => $exception->getMessage(),
                'raw_response' => null,
            ];
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

        $parsedResponse = BingwaUssdResponse::parseDecodedNativePayload($decoded);
        $success = $parsedResponse['success'];
        $message = $parsedResponse['message'];
        $bridgeError = $parsedResponse['bridge_error'];

        if ($bridgeError !== null && $bridgeError !== '' && ! $success) {
            return [
                'success' => false,
                'message' => $bridgeError,
                'raw_response' => $rawResponse,
            ];
        }

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
