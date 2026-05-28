<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Throwable;

final class MpesaReceivedSmsParser
{
    /**
     * @var array<int, string>
     */
    private const TRUSTED_SENDER_IDS = ['MPESA', 'M-PESA'];

    public function parse(string $body): ?MpesaReceivedSms
    {
        $body = $this->normalizeWhitespace($body);

        if ($parsed = $this->parseFirstReceiveFormat($body)) {
            return $parsed;
        }

        if ($parsed = $this->parseDateFirstReceiveFormat($body)) {
            return $parsed;
        }

        return null;
    }

    public function isTrustedSender(string $sender, bool $allowAllSenders = false): bool
    {
        if ($allowAllSenders) {
            return true;
        }

        return in_array(strtoupper(trim($sender)), self::TRUSTED_SENDER_IDS, true);
    }

    private function parseAmount(string $amount): ?string
    {
        $normalized = str_replace(',', '', trim($amount));

        if ($normalized === '' || ! is_numeric($normalized)) {
            return null;
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function parseOccurredAt(string $date, string $time): ?Carbon
    {
        $value = trim($date).' '.strtoupper(preg_replace('/\s+/', ' ', trim($time)));

        foreach (['!j/n/y g:i A', '!j/n/Y g:i A'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value, AppTimezone::name());
                $errors = Carbon::getLastErrors();

                if ($parsed instanceof Carbon && (! is_array($errors) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $parsed;
                }
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function normalizeWhitespace(string $body): string
    {
        return trim(preg_replace('/\s+/', ' ', $body) ?? '');
    }

    private function parseFirstReceiveFormat(string $body): ?MpesaReceivedSms
    {
        if (! preg_match('/^\s*(?<code>[A-Z0-9]+)\s+Confirmed\.\s*(?<rest>.*)$/iu', $body, $matches)) {
            return null;
        }

        if (! preg_match('/^You have received Ksh(?<amount>[\d,]+\.\d{2}) from (?<sender>.+?) (?<phone>(?:\+?254|0)?(?:7\d{8}|1\d{8})) on (?<date>\d{1,2}\/\d{1,2}\/\d{2,4}) at (?<time>\d{1,2}:\d{2}\s*[AP]M)(?:\.\s*New M-?PESA balance is Ksh[\d,]+(?:\.\d{2})?\.)?\.?$/iu', trim((string) $matches['rest']), $payload)) {
            return null;
        }

        return $this->buildSms($matches['code'], $payload);
    }

    private function parseDateFirstReceiveFormat(string $body): ?MpesaReceivedSms
    {
        if (! preg_match('/^\s*(?<code>[A-Z0-9]+)\s+Confirmed\.\s*on\s+(?<date>\d{1,2}\/\d{1,2}\/\d{2,4})\s+at\s+(?<time>\d{1,2}:\d{2}\s*[AP]M)\s*KSH\.?\s*(?<amount>[\d,]+(?:\.\d{1,2})?)\s+received\s+from\s+(?<phone>(?:\+?254|0)?(?:7\d{8}|1\d{8}))\s+(?<sender>.+?)(?:\.\s|$)/iu', $body, $matches)) {
            return null;
        }

        return $this->buildSms($matches['code'], $matches);
    }

    /**
     * @param  array<string, string>  $matches
     */
    private function buildSms(string $code, array $matches): ?MpesaReceivedSms
    {
        $amount = $this->parseAmount((string) ($matches['amount'] ?? ''));
        $phone = KenyanPhoneNumber::normalizeToLocal((string) ($matches['phone'] ?? ''));
        $occurredAt = $this->parseOccurredAt((string) ($matches['date'] ?? ''), (string) ($matches['time'] ?? ''));

        if ($amount === null || $occurredAt === null || ! KenyanPhoneNumber::isLocalKenyanMobile($phone)) {
            return null;
        }

        return new MpesaReceivedSms(
            code: strtoupper(trim($code)),
            amount: $amount,
            senderName: $this->normalizeName((string) ($matches['sender'] ?? '')),
            senderPhone: $phone,
            occurredAt: $occurredAt,
        );
    }

    private function normalizeName(string $name): string
    {
        return trim($this->normalizeWhitespace($name), " \t\n\r\0\x0B.");
    }
}
