<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
