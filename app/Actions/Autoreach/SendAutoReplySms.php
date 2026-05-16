<?php

namespace App\Actions\Autoreach;

use Illuminate\Support\Facades\Log;
use Throwable;

class SendAutoReplySms
{
    /**
     * Send an outbound auto-reply SMS through the NativePHP Android bridge.
     *
     * @return array{success: bool, message: string, retryable: bool, raw_response: string|null}
     */
    public function send(string $recipientPhone, string $message, int $simSlot): array
    {
        $recipientPhone = trim($recipientPhone);
        $message = trim($message);

        if ($recipientPhone === '' || $message === '') {
            return [
                'success' => false,
                'message' => __('The recipient phone number or SMS body is empty.'),
                'retryable' => false,
                'raw_response' => null,
            ];
        }

        if (! function_exists('nativephp_call')) {
            return [
                'success' => false,
                'message' => __('Native SMS bridge is unavailable.'),
                'retryable' => false,
                'raw_response' => null,
            ];
        }

        if (! preg_match('/^07\d{8}$/', $recipientPhone)) {
            return [
                'success' => false,
                'message' => __('The recipient phone number must be a local Kenyan mobile number.'),
                'retryable' => false,
                'raw_response' => null,
            ];
        }

        if (! in_array($simSlot, [0, 1], true)) {
            return [
                'success' => false,
                'message' => __('The selected SIM slot is invalid.'),
                'retryable' => false,
                'raw_response' => null,
            ];
        }

        try {
            $rawResponse = nativephp_call('SendSms', json_encode([
                'destination' => $recipientPhone,
                'message' => $message,
                'simSlot' => $simSlot,
            ], JSON_THROW_ON_ERROR));
        } catch (Throwable $throwable) {
            Log::warning('Auto-reply SMS bridge threw an exception.', [
                'message' => $throwable->getMessage(),
                'sim_slot' => $simSlot,
                'destination' => $recipientPhone,
            ]);

            report($throwable);

            return [
                'success' => false,
                'message' => $throwable->getMessage(),
                'retryable' => true,
                'raw_response' => null,
            ];
        }

        if (! is_string($rawResponse) || $rawResponse === '') {
            return [
                'success' => false,
                'message' => __('The native SMS bridge returned no response.'),
                'retryable' => true,
                'raw_response' => null,
            ];
        }

        $decoded = json_decode($rawResponse, true);

        if (! is_array($decoded)) {
            return [
                'success' => false,
                'message' => __('The native SMS bridge returned an invalid response.'),
                'retryable' => true,
                'raw_response' => $rawResponse,
            ];
        }

        $nativeData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $success = (bool) ($nativeData['success'] ?? false);
        $responseMessage = trim((string) ($nativeData['message'] ?? $decoded['message'] ?? ''));

        if ($responseMessage === '') {
            $responseMessage = $success
                ? __('SMS submitted successfully.')
                : __('SMS delivery failed.');
        }

        if ($success) {
            return [
                'success' => true,
                'message' => $responseMessage,
                'retryable' => false,
                'raw_response' => $rawResponse,
            ];
        }

        return [
            'success' => false,
            'message' => $responseMessage,
            'retryable' => $this->isRetryableFailure($responseMessage),
            'raw_response' => $rawResponse,
        ];
    }

    private function isRetryableFailure(string $message): bool
    {
        $normalizedMessage = strtolower(trim((string) preg_replace('/\s+/', ' ', $message)));

        return str_contains($normalizedMessage, 'timeout')
            || str_contains($normalizedMessage, 'temporarily')
            || str_contains($normalizedMessage, 'bridge')
            || str_contains($normalizedMessage, 'network')
            || str_contains($normalizedMessage, 'exception')
            || str_contains($normalizedMessage, 'error');
    }
}
