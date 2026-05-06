<?php

namespace App\Models;

use Database\Factories\AutoRenewalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $offer_id
 * @property string $customer_phone
 * @property Carbon $scheduled_for
 * @property bool $auto_renew
 * @property int $renew_days
 * @property string $status
 * @property Carbon|null $last_attempt_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $cancelled_at
 * @property string|null $notes
 * @property-read User $user
 * @property-read Offer|null $offer
 */
#[Fillable([
    'user_id',
    'offer_id',
    'customer_phone',
    'scheduled_for',
    'auto_renew',
    'renew_days',
    'status',
    'last_attempt_at',
    'processed_at',
    'cancelled_at',
    'notes',
])]
class AutoRenewal extends Model
{
    /** @use HasFactory<AutoRenewalFactory> */
    use HasFactory;

    /**
     * Get the user that owns the auto-renewal.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the offer that should be renewed.
     */
    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_renew' => 'boolean',
            'scheduled_for' => 'datetime',
            'last_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'renew_days' => 'integer',
        ];
    }
}
