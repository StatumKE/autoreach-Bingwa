<?php

namespace App\Support;

final class BingwaUssdResponse
{
    /**
     * @param  array<string, mixed>  $decoded
     * @return array{success: bool, message: string, bridge_error: string|null}
     */
    public static function parseDecodedNativePayload(array $decoded): array
    {
        $nativeData = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;
        $success = (bool) ($nativeData['success'] ?? false);
        $message = (string) ($nativeData['message'] ?? $decoded['message'] ?? '');
        $bridgeError = is_string($decoded['error'] ?? null) ? $decoded['error'] : null;

        if (! $success && self::messageIndicatesSuccess($message)) {
            $success = true;
        }

        return [
            'success' => $success,
            'message' => $message,
            'bridge_error' => $bridgeError,
        ];
    }

    /**
     * Carrier success copy is the source of truth when the native bridge
     * wrapper misclassifies a top-level JSON response as an error.
     */
    public static function messageIndicatesSuccess(?string $message): bool
    {
        $normalizedMessage = strtolower(trim((string) preg_replace('/\s+/', ' ', $message ?? '')));

        if ($normalizedMessage === '') {
            return false;
        }

        foreach ([
            'submitted successfully',
            'completed successfully',
            'request completed',
            'transaction completed',
            'keep selling',
            'bingwa sokoni champion',
        ] as $successPhrase) {
            if (str_contains($normalizedMessage, $successPhrase)) {
                return true;
            }
        }

        return false;
    }
}
