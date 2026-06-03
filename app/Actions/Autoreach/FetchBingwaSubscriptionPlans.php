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
    private const CACHE_TTL_MINUTES = 60;

    /**
     * Fetch the available Bingwa subscription plans for the authenticated user.
     *
     * @return array{plans: array<int, array<string, mixed>>, sambaza_line: ?string, sambaza_ussd_prompts: array<int, mixed>}
     */
    public function fetch(User $user, bool $forceRefresh = false): array
    {
        $user = $this->freshUser($user);
        $deviceToken = $this->resolveDeviceToken($user);

        if ($forceRefresh) {
            return $this->fetchFresh($user, $deviceToken);
        }

        return Cache::flexible(
            $this->cacheKey($user->id, $deviceToken),
            [1800, 3600],
            function () use ($user, $deviceToken): array {
                return $this->fetchFresh($user, $deviceToken);
            }
        );
    }

    /**
     * Clear the cached plans for a user.
     */
    public function forget(User $user): void
    {
        $user = $this->freshUser($user);
        $registration = $user->bingwaDeviceRegistration;
        if ($registration !== null && filled($registration->device_token)) {
            Cache::forget($this->cacheKey($user->id, $registration->device_token));
        }
    }

    /**
     * Peek at the currently cached plans without triggering a backend request.
     *
     * @return array{plans: array<int, array<string, mixed>>, sambaza_line: ?string, sambaza_ussd_prompts: array<int, mixed>}|null
     */
    public function cached(User $user): ?array
    {
        $user = $this->freshUser($user);
        $deviceToken = $this->resolveDeviceToken($user);
        $cachedPlans = Cache::get($this->cacheKey($user->id, $deviceToken));

        return is_array($cachedPlans) ? $cachedPlans : null;
    }

    private function fetchFresh(User $user, string $deviceToken): array
    {
        $effectiveDeviceToken = $deviceToken;
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
                $effectiveDeviceToken = $recoveredToken;
            }

            $response = $this->requestPlans($recoveredToken);
        }

        if ($response->successful()) {
            Log::debug('📦 Plans response received', ['plans_count' => count($response->json('plans') ?? $response->json('data') ?? [])]);

            $plans = $this->normalizePlansResponse($response);

            Cache::put(
                $this->cacheKey($user->id, $effectiveDeviceToken),
                $plans,
                now()->addMinutes(self::CACHE_TTL_MINUTES),
            );

            return $plans;
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

    private function resolveDeviceToken(User $user): string
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

        return $registration->device_token;
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

    private function freshUser(User $user): User
    {
        return $user->fresh(['bingwaDeviceRegistration']) ?? $user;
    }
}
