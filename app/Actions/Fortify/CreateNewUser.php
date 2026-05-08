<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Jobs\SyncBingwaFcmTokenJob;
use App\Models\DeviceSetting;
use App\Models\User;
use Illuminate\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = null;

        try {
            $user = Cache::lock('autoreach-single-account-registration', 10)->block(5, function () use ($input): User {
                return retry(2, function () use ($input): User {
                    return DB::transaction(function () use ($input): User {
                        if (User::query()->exists()) {
                            throw ValidationException::withMessages([
                                'email' => __('This app already has a registered account. Only one account is supported per installation.'),
                            ]);
                        }

                        $user = User::create([
                            'name' => $input['name'],
                            'email' => $input['email'],
                            'autoreach_connect_id' => $input['autoreach_connect_id'],
                            'password' => $input['password'],
                        ]);

                        DeviceSetting::query()->create([
                            'user_id' => $user->id,
                            'operator_identity' => $user->name,
                            'primary_transaction_sim' => 'slot_1',
                            'sms_auto_reply_sim' => 'slot_1',
                            'app_interface_mode' => 'express',
                            'auto_reschedule_rejected' => true,
                            'retry_tomorrow_at' => '12:30 AM',
                            'ussd_timeout_seconds' => 30,
                            'intelligent_auto_retry' => true,
                            'retry_interval_minutes' => 1,
                            'max_attempts' => 2,
                            'retry_network_issues' => true,
                        ]);

                        return $user;
                    });
                }, 0);
            });
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages([
                'email' => __('Registration is busy right now. Please try again.'),
            ]);
        }

        session()->flash('request_setup_permissions_after_onboarding', true);

        $this->startNativePushEnrollment($user);

        return $user;
    }

    private function startNativePushEnrollment(User $user): void
    {
        try {
            $flowId = (string) Str::uuid();

            Log::debug('Bingwa FCM enrollment job queued after registration.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);

            SyncBingwaFcmTokenJob::dispatch($user->getKey(), $flowId);

            Log::debug('Bingwa FCM enrollment job dispatched to the queue.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId,
            ]);
        } catch (\Throwable $throwable) {
            Log::warning('Bingwa FCM enrollment job queueing failed after registration.', [
                'user_id' => $user->getKey(),
                'flow_id' => $flowId ?? null,
                'message' => $throwable->getMessage(),
            ]);

            report($throwable);
        }
    }
}
