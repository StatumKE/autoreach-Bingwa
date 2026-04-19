<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'offer_id',
    'transaction_id',
    'mpesa_code',
    'sender_phone',
    'sender_name',
    'amount',
    'offer_name',
    'offer_type',
    'matched_offer',
    'balance',
    'occurred_at',
    'status',
    'status_desc',
    'retry_count',
    'processed_at',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    /**
     * Get the user that owns the transaction.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the offer that matches this transaction.
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
            'amount' => 'decimal:2',
            'matched_offer' => 'array',
            'balance' => 'array',
            'occurred_at' => 'datetime',
            'processed_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }
}
