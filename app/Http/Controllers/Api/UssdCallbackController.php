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

        Log::info('📥 [CALLBACK RECEIVE] Asynchronous USSD callback received', [
            'transaction_id' => $transactionId,
            'success' => $success,
            'message' => $message,
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
