<?php

namespace App\Http\Controllers\Api;

use App\Services\AndroidRuntimeScheduler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NativeRuntimeTickController
{
    public function __invoke(Request $request, AndroidRuntimeScheduler $scheduler): JsonResponse
    {
        $runtimeHeader = $request->header('X-Bingwa-Runtime');
        $isLoopbackRequest = $this->isLoopbackRequest($request);

        Log::debug('Bingwa runtime tick request received.', [
            'component' => 'runtime_tick',
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'loopback' => $isLoopbackRequest,
            'runtime_header' => $runtimeHeader,
        ]);

        if (! $isLoopbackRequest || $runtimeHeader !== 'android') {
            Log::debug('Bingwa runtime tick request rejected.', [
                'component' => 'runtime_tick',
                'ip' => $request->ip(),
                'host' => $request->getHost(),
                'loopback' => $isLoopbackRequest,
                'runtime_header' => $runtimeHeader,
            ]);

            return response()->json([
                'status' => 'forbidden',
            ], 403);
        }

        Log::debug('Bingwa runtime tick request accepted.', [
            'component' => 'runtime_tick',
            'ip' => $request->ip(),
            'host' => $request->getHost(),
            'runtime_header' => $runtimeHeader,
        ]);

        $tasks = $scheduler->runDueTasks();

        Log::debug('Bingwa runtime tick tasks resolved.', [
            'component' => 'runtime_tick',
            'tasks' => $tasks,
        ]);

        return response()->json([
            'status' => 'ok',
            'tasks' => $tasks,
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
