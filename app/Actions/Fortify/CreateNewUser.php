<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Jobs\SyncBingwaFcmTokenJob;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
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

        $user = retry(2, function () use ($input): User {
            return DB::transaction(function () use ($input): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'autoreach_connect_id' => $input['autoreach_connect_id'],
                    'password' => $input['password'],
                ]);

                return $user;
            });
        }, 0);

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
