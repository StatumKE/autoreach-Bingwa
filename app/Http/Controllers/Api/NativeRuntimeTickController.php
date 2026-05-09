<?php

namespace App\Http\Controllers\Api;

use App\Services\AndroidRuntimeScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NativeRuntimeTickController
{
    public function __invoke(Request $request, AndroidRuntimeScheduler $scheduler): JsonResponse
    {
        if (! $this->isLoopbackRequest($request) || $request->header('X-Bingwa-Runtime') !== 'android') {
            return response()->json([
                'status' => 'forbidden',
            ], 403);
        }

        return response()->json([
            'status' => 'ok',
            'tasks' => $scheduler->runDueTasks(),
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
