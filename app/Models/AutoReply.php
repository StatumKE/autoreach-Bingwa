<?php

namespace App\Models;

use Database\Factories\AutoReplyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $trigger_condition
 * @property string $reply_message
 * @property bool $is_active
 * @property bool $is_default
 * @property int $sort_order
 * @property-read User $user
 */
#[Fillable([
    'user_id',
    'name',
    'trigger_condition',
    'reply_message',
    'is_active',
    'is_default',
    'sort_order',
])]
class AutoReply extends Model
{
    /** @use HasFactory<AutoReplyFactory> */
    use HasFactory;

    /**
     * Get the user that owns the auto reply.
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
            'is_active' => 'boolean',
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
