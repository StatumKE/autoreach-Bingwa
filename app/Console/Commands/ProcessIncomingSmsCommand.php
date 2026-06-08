<?php

namespace App\Console\Commands;

use App\Actions\Autoreach\ProcessIncomingMpesaSms;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use JsonException;

#[Signature('bingwa:process-incoming-sms {--payload= : Base64url-encoded JSON SMS payload}')]
#[Description('Process one incoming SMS payload from the Android receiver.')]
class ProcessIncomingSmsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(ProcessIncomingMpesaSms $processIncomingMpesaSms): int
    {
        $encodedPayload = $this->option('payload');

        if (! is_string($encodedPayload) || trim($encodedPayload) === '') {
            $this->error('The --payload option is required.');

            return self::FAILURE;
        }

        try {
            Log::debug('ProcessIncomingSmsCommand started.', [
                'component' => 'incoming_sms',
                'payload_length' => strlen($encodedPayload),
            ]);
            $payload = $this->decodePayload($encodedPayload);
            $result = $processIncomingMpesaSms->process($payload);
            $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            Log::debug('ProcessIncomingSmsCommand completed.', [
                'component' => 'incoming_sms',
                'result' => $result,
            ]);

            return self::SUCCESS;
        } catch (JsonException $exception) {
            $this->error('The --payload option must contain valid base64url JSON.');
            report($exception);

            return self::FAILURE;
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodePayload(string $encodedPayload): array
    {
        $payload = strtr(trim($encodedPayload), '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new JsonException('Invalid base64url payload.');
        }

        $data = json_decode($decoded, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new JsonException('Decoded payload is not an object.');
        }

        return $data;
    }
}
