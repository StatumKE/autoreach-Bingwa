<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FetchBingwaSubscriptionPlans
{
    private const CACHE_TTL_MINUTES = 2;

    /**
     * Fetch the available Bingwa subscription plans for the authenticated user.
     *
     * @return array{plans: array<int, array<string, mixed>>, sambaza_line: ?string, sambaza_ussd_prompts: array<int, mixed>}
     */
    public function fetch(User $user, bool $forceRefresh = false): array
    {
        $registration = $user->bingwaDeviceRegistration;

        if ($registration === null) {
            throw ValidationException::withMessages([
                'device_token' => __('Register this device before viewing subscription plans.'),
            ]);
        }

        if (! is_string($registration->device_token) || $registration->device_token === '') {
            throw ValidationException::withMessages([
                'device_token' => __('The saved Bingwa device token is missing. Recover the token before viewing subscription plans.'),
            ]);
        }

        if ($forceRefresh) {
            return $this->fetchFresh($user, $registration->device_token);
        }

        $cacheKey = $this->cacheKey($user->id, $registration->device_token);
        $cachedPlans = Cache::get($cacheKey);

        if (is_array($cachedPlans)) {
            return $cachedPlans;
        }

        $plans = $this->fetchFresh($user, $registration->device_token);

        Cache::put(
            $this->cacheKey($user->id, $user->refresh()->bingwaDeviceRegistration?->device_token ?? $registration->device_token),
            $plans,
            now()->addMinutes(self::CACHE_TTL_MINUTES),
        );

        return $plans;
    }

    private function fetchFresh(User $user, string $deviceToken): array
    {
        $response = $this->requestPlans($deviceToken);

        if ($response->status() === 401) {
            Log::warning('🔐 Plans fetch returned unauthorized; attempting token recovery and retry.', [
                'user_id' => $user->getKey(),
            ]);

            $recoveredRegistration = app(RecoverBingwaDeviceToken::class)->recover($user);
            $recoveredToken = $recoveredRegistration->device_token;

            if (! is_string($recoveredToken) || $recoveredToken === '') {
                throw ValidationException::withMessages([
                    'device_token' => __('The backend returned an invalid recovered device token.'),
                ]);
            }

            if ($recoveredToken !== $deviceToken) {
                Cache::forget($this->cacheKey($user->id, $deviceToken));
            }

            $response = $this->requestPlans($recoveredToken);
        }

        if ($response->successful()) {
            Log::debug('📦 Plans response received', ['plans_count' => count($response->json('plans') ?? $response->json('data') ?? [])]);

            return $this->normalizePlansResponse($response);
        }

        Log::error('❌ Plans fetch failed', ['status' => $response->status(), 'body' => $response->json()]);

        $this->throwValidationException($response);
    }

    /**
     * @return array{plans: array<int, array<string, mixed>>, sambaza_line: ?string, sambaza_ussd_prompts: array<int, mixed>}
     */
    private function normalizePlansResponse(Response $response): array
    {
        $plans = $response->json('plans');

        if (! is_array($plans) || $plans === []) {
            $plans = $response->json('data');
        }

        if (! is_array($plans)) {
            $plans = [];
        }

        return [
            'plans' => array_values(array_filter($plans, static fn (mixed $plan): bool => is_array($plan))),
            'sambaza_line' => $response->json('sambaza_line'),
            'sambaza_ussd_prompts' => is_array($response->json('sambaza_ussd_prompts'))
                ? $response->json('sambaza_ussd_prompts')
                : [],
        ];
    }

    private function throwValidationException(Response $response): never
    {
        $errors = $response->json('errors');

        if (is_array($errors) && $errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        throw ValidationException::withMessages([
            'device_token' => $response->json('message') ?? __('Unable to load subscription plans right now.'),
        ]);
    }

    private function requestPlans(string $deviceToken): Response
    {
        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        Log::debug("🌐 Fetching plans from: {$baseUrl}/api/v1/subscription/plans/hybrid");

        return Http::baseUrl($baseUrl)
            ->retry(3, 100, function (\Throwable $exception): bool {
                return $exception instanceof ConnectionException;
            }, throw: false)
            ->acceptJson()
            ->asJson()
            ->withToken($deviceToken)
            ->timeout(30)
            ->get('/api/v1/subscription/plans/hybrid');
    }

    private function cacheKey(int $userId, string $deviceToken): string
    {
        return sprintf('autoreach.subscription_plans.%d.%s', $userId, sha1($deviceToken));
    }
}
