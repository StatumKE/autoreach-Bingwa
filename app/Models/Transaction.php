<?php

namespace App\Models;

use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $offer_id
 * @property string $transaction_id
 * @property string $mpesa_code
 * @property string $sender_phone
 * @property string|null $sender_name
 * @property float $amount
 * @property string $offer_name
 * @property string $offer_type
 * @property array<string, mixed>|null $matched_offer
 * @property array<string, mixed>|null $balance
 * @property Carbon|null $occurred_at
 * @property Carbon|null $next_attempt_at
 * @property string $status
 * @property string|null $status_desc
 * @property int $retry_count
 * @property Carbon|null $processed_at
 * @property int|null $auto_reply_id
 * @property string|null $auto_reply_trigger_condition
 * @property string|null $auto_reply_message
 * @property string|null $auto_reply_recipient_phone
 * @property string|null $auto_reply_sim_slot
 * @property string|null $auto_reply_status
 * @property int $auto_reply_attempts
 * @property Carbon|null $auto_reply_sent_at
 * @property Carbon|null $auto_reply_failed_at
 * @property string|null $auto_reply_failure_reason
 * @property string|null $raw_sms
 * @property-read User|null $user
 * @property-read Offer|null $offer
 * @property-read AutoReply|null $autoReply
 */
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
    'next_attempt_at',
    'status',
    'status_desc',
    'retry_count',
    'processed_at',
    'auto_reply_id',
    'auto_reply_trigger_condition',
    'auto_reply_message',
    'auto_reply_recipient_phone',
    'auto_reply_sim_slot',
    'auto_reply_status',
    'auto_reply_attempts',
    'auto_reply_sent_at',
    'auto_reply_failed_at',
    'auto_reply_failure_reason',
    'raw_sms',
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
     * Get the auto reply template that produced the SMS.
     */
    public function autoReply(): BelongsTo
    {
        return $this->belongsTo(AutoReply::class);
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
            'next_attempt_at' => 'datetime',
            'processed_at' => 'datetime',
            'retry_count' => 'integer',
            'auto_reply_attempts' => 'integer',
            'auto_reply_sent_at' => 'datetime',
            'auto_reply_failed_at' => 'datetime',
        ];
    }
}
