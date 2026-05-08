<?php

namespace Native\Mobile\Providers\Commands;

use Illuminate\Console\Command;
use ReflectionClass;
use Throwable;

class DispatchPushEvent extends Command
{
    protected $signature = 'native:dispatch-push-event {--event=} {--payload=}';

    protected $description = 'Dispatch a push notification event with the given payload';

    public function handle(): void
    {
        $eventClass = base64_decode($this->option('event'));
        $rawPayload = base64_decode($this->option('payload'));
        $payload = json_decode($rawPayload, true) ?? [];

        if (! class_exists($eventClass)) {
            $this->error("Event class {$eventClass} does not exist.");

            return;
        }

        try {
            event($this->makeEvent($eventClass, $payload));
        } catch (Throwable $throwable) {
            $this->error("Failed to dispatch {$eventClass}: {$throwable->getMessage()}");
        }
    }

    /**
     * @param  class-string  $eventClass
     * @param  array<string, mixed>  $payload
     */
    private function makeEvent(string $eventClass, array $payload): object
    {
        $reflection = new ReflectionClass($eventClass);
        $constructor = $reflection->getConstructor();

        if ($constructor === null || $constructor->getNumberOfParameters() === 0) {
            return $reflection->newInstance();
        }

        if ($constructor->getNumberOfParameters() === 1) {
            return $reflection->newInstance($payload);
        }

        return $reflection->newInstanceArgs($payload);
    }
}
