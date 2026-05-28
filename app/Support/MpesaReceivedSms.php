<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final readonly class MpesaReceivedSms
{
    public function __construct(
        public string $code,
        public string $amount,
        public string $senderName,
        public string $senderPhone,
        public Carbon $occurredAt,
    ) {}

    public function amountAsFloat(): float
    {
        return (float) $this->amount;
    }

    public function amountAsOfferPrice(): ?int
    {
        $amount = $this->amountAsFloat();

        if (floor($amount) !== $amount) {
            return null;
        }

        return (int) $amount;
    }
}
