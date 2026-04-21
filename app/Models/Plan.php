<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $user_id
 * @property string $type
 * @property bool $is_active
 * @property Carbon|null $expires_at
 * @property int $duration_days
 * @property int|null $ussd_requests_included
 * @property int $ussd_counter
 * @property int $price
 * @property-read User $user
 */
class Plan extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'expires_at' => 'datetime',
            'duration_days' => 'integer',
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
