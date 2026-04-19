<?php

namespace App\Actions\Fortify;

use App\Actions\Autoreach\RegisterBingwaDevice;
use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function __construct(
        private readonly RegisterBingwaDevice $registerBingwaDevice,
    ) {}

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

        if ($this->registerBingwaDevice->isCurrentDeviceRegistered()) {
            throw ValidationException::withMessages([
                'email' => __('This account is already registered on this device. Use the APK on a new device to register another installation.'),
            ]);
        }

        $backendRegistration = $this->registerBingwaDevice->registerOnBackend(new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'autoreach_connect_id' => $input['autoreach_connect_id'],
        ]));

        return retry(2, function () use ($input, $backendRegistration): User {
            return DB::transaction(function () use ($input, $backendRegistration): User {
                $user = User::create([
                    'name' => $input['name'],
                    'email' => $input['email'],
                    'autoreach_connect_id' => $input['autoreach_connect_id'],
                    'password' => $input['password'],
                ]);

                $this->registerBingwaDevice->persistRegistration($user, $backendRegistration);

                return $user;
            });
        }, 0);
    }
}
