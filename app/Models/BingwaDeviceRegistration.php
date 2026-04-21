<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $user_id
 * @property string|null $device_token
 * @property string|null $bhc_code
 * @property int|null $backend_device_id
 * @property string|null $app_type
 * @property string|null $backend_device_type
 * @property string|null $connect_device_id
 * @property string|null $linked_connect_device_id
 * @property string|null $device_name
 * @property string|null $app_version
 * @property array<string, mixed>|null $device_info
 * @property array<string, mixed>|null $metadata
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'hardware_id',
    'device_token',
    'bhc_code',
    'backend_device_id',
    'app_type',
    'backend_device_type',
    'connect_device_id',
    'linked_connect_device_id',
    'device_name',
    'app_version',
    'device_info',
    'metadata',
])]
class BingwaDeviceRegistration extends Model
{
    /**
     * Get the user that owns the registration.
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
            'backend_device_id' => 'integer',
            'device_info' => 'array',
            'metadata' => 'array',
        ];
    }
}
