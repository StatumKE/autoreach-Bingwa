<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property string $name
 * @property string $email
 * @property string|null $autoreach_connect_id
 * @property-read BingwaDeviceRegistration|null $bingwaDeviceRegistration
 * @property-read Collection<int, AutoRenewal> $autoRenewals
 * @property-read Collection<int, AutoReply> $autoReplies
 * @property-read Collection<int, Offer> $offers
 * @property-read Collection<int, Plan> $plans
 * @property-read DeviceSetting|null $deviceSetting
 * @property-read Collection<int, Transaction> $transactions
 */
#[Fillable(['name', 'email', 'autoreach_connect_id', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        return Str::of($this->name)
            ->explode(' ')
            ->take(2)
            ->map(fn ($word) => Str::substr($word, 0, 1))
            ->implode('');
    }

    /**
     * Get the user's Bingwa device registration.
     */
    public function bingwaDeviceRegistration(): HasOne
    {
        return $this->hasOne(BingwaDeviceRegistration::class);
    }

    /**
     * Get the offers owned by the user.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Get the auto-renewal schedules owned by the user.
     *
     * @return HasMany<AutoRenewal, $this>
     */
    public function autoRenewals(): HasMany
    {
        return $this->hasMany(AutoRenewal::class);
    }

    /**
     * Get the auto reply rules owned by the user.
     *
     * @return HasMany<AutoReply, $this>
     */
    public function autoReplies(): HasMany
    {
        return $this->hasMany(AutoReply::class);
    }

    /**
     * Get the subscription plans owned by the user.
     *
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }

    /**
     * Get the user's device settings.
     */
    public function deviceSetting(): HasOne
    {
        return $this->hasOne(DeviceSetting::class);
    }

    /**
     * Get the transactions owned by the user.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
}
