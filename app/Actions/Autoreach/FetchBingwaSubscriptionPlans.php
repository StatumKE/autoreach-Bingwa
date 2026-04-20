<?php

namespace App\Actions\Autoreach;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FetchBingwaSubscriptionPlans
{
    /**
     * Fetch the available Bingwa subscription plans for the authenticated user.
     *
     * @return array{plans: array<int, array<string, mixed>>, sambaza_line: ?string, sambaza_ussd_prompts: array<int, mixed>}
     */
    public function fetch(User $user): array
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

        $baseUrl = rtrim((string) config('services.autoreach.backend_url'), '/');
        Log::debug("🌐 Fetching plans from: {$baseUrl}/api/v1/subscription/plans/hybrid");

        $response = Http::baseUrl($baseUrl)
            ->retry(3, 100)
            ->acceptJson()
            ->asJson()
            ->withToken($registration->device_token)
            ->timeout(30)
            ->get('/api/v1/subscription/plans/hybrid');

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
}
