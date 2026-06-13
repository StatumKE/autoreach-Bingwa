<?php

use App\Jobs\UpdateRemoteTransactionStatusJob;
use App\Models\BingwaDeviceRegistration;
use App\Models\User;
use App\Services\BingwaDeviceContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
        remoteTransactionId: 'REMOTE-123',
        status: 'successful',
        ussdResponse: null,
        airtimeUsed: null,
        executionTimeMs: null,
        executedAt: now()->toIso8601String(),
        userId: $user->id,
    ))->handle();

    expect($user->refresh()->bingwaDeviceRegistration?->device_token)->toBe('new-device-token-401');

    Http::assertSentCount(3);
});

it('respects the token recovery cooldown cache lock', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create();
    cache()->forever(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY, 'HW-12345');

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token-401',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Http::fake(function ($request) {
        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/token/recover') {
            return Http::response([
                'status' => 'failed',
                'message' => 'Validation error',
            ], 422);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/transactions/REMOTE-123/status') {
            return Http::response([
                'status' => 'failed',
                'message' => 'Unauthorized device token',
            ], 401);
        }

        return Http::response([], 404);
    });

    // Run the job once. It will try to recover, fail, and activate cooldown.
    try {
        (new UpdateRemoteTransactionStatusJob(
            remoteTransactionId: 'REMOTE-123',
            status: 'successful',
            ussdResponse: null,
            airtimeUsed: null,
            executionTimeMs: null,
            executedAt: now()->toIso8601String(),
            userId: $user->id,
        ))->handle();
    } catch (Throwable $e) {
        // Suppress initial failure exception
    }

    Http::assertSentCount(2); // 1 status update + 1 recovery request

    // Run a second time. The cooldown should trigger immediately without making another recover request.
    try {
        (new UpdateRemoteTransactionStatusJob(
            remoteTransactionId: 'REMOTE-123',
            status: 'successful',
            ussdResponse: null,
            airtimeUsed: null,
            executionTimeMs: null,
            executedAt: now()->toIso8601String(),
            userId: $user->id,
        ))->handle();
    } catch (Throwable $e) {
        // Suppress failure exception
    }

    Http::assertSentCount(3); // 2nd status update only (no additional recovery request)
});

it('automatically re-registers the device if the backend returns a not found 422 error during recovery', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create([
        'autoreach_connect_id' => 'CONNECT-ID',
    ]);
    cache()->forever(BingwaDeviceContext::HARDWARE_ID_CACHE_KEY, 'HW-12345');

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'old-device-token-401',
        'bhc_code' => 'BHC-OLD',
    ]);

    Http::fake(function ($request) {
        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/token/recover') {
            return Http::response([
                'status' => 'failed',
                'message' => 'The specified device was not found for this account.',
                'errors' => [
                    'bhc_code' => ['The specified device was not found for this account.'],
                ],
            ], 422);
        }

        if ($request->url() === 'https://backend.example.test/api/v1/auth/device/register/hybrid') {
            return Http::response([
                'status' => 'success',
                'device_token' => 'brand-new-device-token',
                'bhc_code' => 'BHC-NEW',
                'device_id' => 999,
                'app_type' => 'hybridApp',
                'backend_device_type' => 'hybridApp',
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
            && $request->hasHeader('Authorization', 'Bearer brand-new-device-token')) {
            return Http::response([
                'status' => 'accepted',
            ], 200);
        }

        return Http::response([], 404);
    });

    (new UpdateRemoteTransactionStatusJob(
        remoteTransactionId: 'REMOTE-123',
        status: 'successful',
        ussdResponse: null,
        airtimeUsed: null,
        executionTimeMs: null,
        executedAt: now()->toIso8601String(),
        userId: $user->id,
    ))->handle();

    $registration = $user->refresh()->bingwaDeviceRegistration;
    expect($registration?->device_token)->toBe('brand-new-device-token');
    expect($registration?->bhc_code)->toBe('BHC-NEW');
});

it('logs validation failures when the backend rejects a remote status update', function (): void {
    config(['services.autoreach.backend_url' => 'https://backend.example.test']);

    $user = User::factory()->create();

    BingwaDeviceRegistration::query()->create([
        'user_id' => $user->id,
        'hardware_id' => 'HW-12345',
        'device_token' => 'raw-device-token',
        'bhc_code' => 'BHC-ZXCVB',
    ]);

    Log::spy();

    Http::fake(function ($request) {
        if ($request->url() === 'https://backend.example.test/api/v1/transactions/REMOTE-123/status') {
            return Http::response([
                'status' => 'failed',
                'message' => 'Validation error',
            ], 422);
        }

        return Http::response([], 404);
    });

    expect(function () use ($user): void {
        (new UpdateRemoteTransactionStatusJob(
            remoteTransactionId: 'REMOTE-123',
            status: 'successful',
            ussdResponse: null,
            airtimeUsed: null,
            executionTimeMs: null,
            executedAt: now()->toIso8601String(),
            userId: $user->id,
        ))->handle();
    })->toThrow(RuntimeException::class);

    Log::shouldHaveReceived('error')
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, '422 Unprocessable Content')
                && ($context['body'] ?? null) === '{"status":"failed","message":"Validation error"}'
                && ($context['payload']['status'] ?? null) === 'successful';
        })
        ->once();
});
