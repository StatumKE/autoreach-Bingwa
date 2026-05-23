<?php

namespace App\Support;

final class BingwaTransactionFailureCode
{
    public static function fromStatusAndMessage(string $status, ?string $message): ?string
    {
        if ($status !== 'failed') {
            return null;
        }

        $lowerMessage = strtolower($message ?? '');

        if (str_contains($lowerMessage, 'already been recommended')
            || str_contains($lowerMessage, 'invalid')
            || str_contains($lowerMessage, 'not allowed')
            || str_contains($lowerMessage, 'rejected')
        ) {
            return 'INVALID_RECIPIENT';
        }

        if (str_contains($lowerMessage, 'insufficient')
            || str_contains($lowerMessage, 'low balance')
            || str_contains($lowerMessage, 'not enough')
        ) {
            return 'LOW_BALANCE';
        }

        if (str_contains($lowerMessage, 'timeout')
            || str_contains($lowerMessage, 'failed to execute')
            || str_contains($lowerMessage, 'failed to reach')
        ) {
            return 'USSD_TIMEOUT';
        }

        if (str_contains($lowerMessage, 'session ended') || str_contains($lowerMessage, 'cancelled')) {
            return 'SESSION_ENDED';
        }

        return 'SYSTEM_ERROR';
    }
}
