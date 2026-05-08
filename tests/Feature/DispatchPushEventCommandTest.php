<?php

use Illuminate\Support\Facades\Event;
use Native\Mobile\Events\PushNotification\PushNotificationReceived;

it('dispatches push notification received events with the full payload array', function (): void {
    Event::fake();

    $payload = base64_encode(json_encode([
        'transaction_id' => '999994',
        'service' => 'smoke-test-new-token',
        'device_id' => '58',
        'payload' => '{"transaction_id":999994,"service":"smoke-test-new-token","device_id":58}',
    ], JSON_THROW_ON_ERROR));

    $event = base64_encode(PushNotificationReceived::class);

    $this->artisan('native:dispatch-push-event', [
        '--event' => $event,
        '--payload' => $payload,
    ])->assertSuccessful();

    Event::assertDispatched(PushNotificationReceived::class, function (PushNotificationReceived $event): bool {
        return $event->data['transaction_id'] === '999994'
            && $event->data['service'] === 'smoke-test-new-token'
            && $event->data['device_id'] === '58';
    });
});
