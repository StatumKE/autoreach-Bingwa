<?php

use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use Illuminate\Support\Facades\Http;

it('recovers the backend device token when updating remote transaction status returns unauthorized', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token-401',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake(function ($request) {
        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/token/recover') {
            return Http::response([
                'status' => 'success',
                'message' => 'Device token recovered.',
                'device_token' => 'new-device-token-401',
                'device_id' => 123,
                'bhc_code' => 'BHC-ZXCVB',
            ], 200);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/transactions/REMOTE-123/status'
            && $request->hasHeader('Authorization', 'Bearer old-device-token-401')) {
            return Http::response([
                'status' => 'failed',
                'message' => 'Unauthorized device token',
            ], 401);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/transactions/REMOTE-123/status'
            && $request->hasHeader('Authorization', 'Bearer new-device-token-401')) {
            return Http::response([
                'status' => 'accepted',
            ], 200);
        }

        return Http::response([], 404);
    });

    (new UpdateRemoteTransactionStatusJob(
        userId: $user->id,
        remoteTransactionId: 'REMOTE-123',
        deviceToken: 'old-device-token-401',
        status: 'successful',
        ussdResponse: null,
        airtimeUsed: null,
        executionTimeMs: null,
        executedAt: now()->toIso8601String(),
    ))->handle();

    expect($user->refresh()->bingwaDeviceRegistration?->device_token)->toBe('new-device-token-401');

    Http::assertSentCount(3);
});
