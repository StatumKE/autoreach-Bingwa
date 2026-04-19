<?php

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?int $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'autoreach_connect_id' => $this->connectIdRules(),
        ];
    }

    /**
     * Get the validation rules used when updating the profile settings page.
     *
     * @return array<string, array<int, ValidationRule|array<mixed>|string>>
     */
    protected function profileUpdateRules(): array
    {
        return [
            'name' => $this->nameRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?int $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate the Autoreach Connect ID.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function connectIdRules(): array
    {
        return ['required', 'string', 'max:255'];
    }
}
