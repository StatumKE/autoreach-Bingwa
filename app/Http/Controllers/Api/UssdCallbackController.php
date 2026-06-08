<?php

namespace App\Http\Controllers\Api;

use App\Actions\Autoreach\CompleteBingwaTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class UssdCallbackController
{
    public function __invoke(Request $request, CompleteBingwaTransaction $completeBingwaTransaction): JsonResponse
    {
        if (! $this->isLoopbackRequest($request)) {
            Log::warning('🛑 [CALLBACK BLOCK] Unauthorized external request rejected by USSD callback endpoint.', [
                'component' => 'ussd_callback',
                'ip' => $request->ip(),
                'host' => $request->getHost(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Forbidden',
            ], 403);
        }

        $transactionId = $request->input('id');
        $success = (bool) $request->input('success');
        $message = trim((string) $request->input('message'));
        $callbackToken = trim((string) $request->input('callback_token'));

        Log::info('📥 [CALLBACK RECEIVE] Asynchronous USSD callback received', [
            'component' => 'ussd_callback',
            'transaction_id' => $transactionId,
            'success' => $success,
            'message' => $message,
            'callback_token' => $callbackToken !== '' ? $callbackToken : null,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $request->all(),
        ]);

        if (! $transactionId) {
            return response()->json([
                'success' => false,
                'message' => 'Missing transaction ID',
            ], 400);
        }

        $completed = $completeBingwaTransaction->complete(
            transactionId: (int) $transactionId,
            status: $success ? 'completed' : 'failed',
            message: $message,
            callbackToken: $callbackToken !== '' ? $callbackToken : null,
        );

        return response()->json([
            'success' => $completed,
        ]);
    }

    private function isLoopbackRequest(Request $request): bool
    {
        $requestIp = $request->ip();
        $remoteAddress = $request->server('REMOTE_ADDR');
        $ip = is_string($requestIp) && trim($requestIp) !== ''
            ? trim($requestIp)
            : (is_string($remoteAddress) ? trim($remoteAddress) : '');

        if (in_array($ip, ['127.0.0.1', '::1'], true)) {
            return true;
        }

        $host = strtolower(trim((string) $request->getHost()));

        return in_array($host, ['127.0.0.1', 'localhost'], true);
    }
}
