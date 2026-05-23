<?php

namespace App\Models;

use Database\Factories\DeviceSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $user_id
 * @property string|null $operator_identity
 * @property string|null $primary_transaction_sim
 * @property string|null $sms_auto_reply_sim
 * @property string|null $app_interface_mode
 * @property bool $auto_reschedule_rejected
 * @property string|null $retry_tomorrow_at
 * @property int $ussd_timeout_seconds
 * @property bool $intelligent_auto_retry
 * @property int $retry_interval_minutes
 * @property int $max_attempts
 * @property bool $retry_network_issues
 * @property bool $transaction_processing_enabled
 * @property float|null $airtime_balance
 * @property string|null $airtime_balance_raw_response
 * @property Carbon|null $airtime_balance_checked_at
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'operator_identity',
    'primary_transaction_sim',
    'sms_auto_reply_sim',
    'app_interface_mode',
    'auto_reschedule_rejected',
    'retry_tomorrow_at',
    'ussd_timeout_seconds',
    'intelligent_auto_retry',
    'retry_interval_minutes',
    'max_attempts',
    'retry_network_issues',
    'transaction_processing_enabled',
    'airtime_balance',
    'airtime_balance_raw_response',
    'airtime_balance_checked_at',
])]
class DeviceSetting extends Model
{
    /** @use HasFactory<DeviceSettingFactory> */
    use HasFactory;

    /**
     * Get the user that owns the device settings.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The "booted" method of the model.
     */
    protected static function booted(): void
    {
        static::saved(function (DeviceSetting $setting) {
            Cache::forget("user_{$setting->user_id}_transaction_processing_enabled");
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_reschedule_rejected' => 'boolean',
            'ussd_timeout_seconds' => 'integer',
            'intelligent_auto_retry' => 'boolean',
            'retry_interval_minutes' => 'integer',
            'max_attempts' => 'integer',
            'retry_network_issues' => 'boolean',
            'transaction_processing_enabled' => 'boolean',
            'airtime_balance' => 'decimal:2',
            'airtime_balance_checked_at' => 'datetime',
        ];
    }

    public static function isTransactionProcessingEnabledForUser(int $userId): bool
    {
        return Cache::remember(
            "user_{$userId}_transaction_processing_enabled",
            300,
            function () use ($userId) {
                $value = self::query()
                    ->where('user_id', $userId)
                    ->value('transaction_processing_enabled');

                return $value === null ? true : (bool) $value;
            }
        );
    }
}
