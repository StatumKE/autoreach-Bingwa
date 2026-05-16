<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property int|null $remote_subscription_id
 * @property string $type
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property Carbon|null $remote_purchase_synced_at
 * @property array<string, mixed>|null $remote_purchase_response
 * @property int $duration_days
 * @property int|null $ussd_requests_included
 * @property int $ussd_counter
 * @property int $price
 * @property-read User $user
 */
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'remote_purchase_synced_at' => 'datetime',
            'remote_purchase_response' => 'array',
            'duration_days' => 'integer',
            'remote_subscription_id' => 'integer',
            'ussd_requests_included' => 'integer',
            'ussd_counter' => 'integer',
            'price' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
