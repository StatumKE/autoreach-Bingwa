<?php

namespace App\Models;

use Database\Factories\DeviceSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        ];
    }
}
