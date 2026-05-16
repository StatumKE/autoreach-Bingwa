<?php

use App\Support\BingwaUssdResponse;

it('parses top-level native success payloads as successful', function (): void {
    $parsed = BingwaUssdResponse::parseDecodedNativePayload([
        'success' => true,
        'message' => 'Recommendation for 0721553678 submitted successfully. Keep selling!! Be a Bingwa Sokoni Champion.',
    ]);

    expect($parsed)->toMatchArray([
        'success' => true,
        'message' => 'Recommendation for 0721553678 submitted successfully. Keep selling!! Be a Bingwa Sokoni Champion.',
        'bridge_error' => null,
    ]);
});

it('uses carrier success text when bridge success is false', function (): void {
    $parsed = BingwaUssdResponse::parseDecodedNativePayload([
        'success' => false,
        'message' => 'Recommendation for 0721553678 submitted successfully. Keep selling!! Be a Bingwa Sokoni Champion.',
    ]);

    expect($parsed['success'])->toBeTrue();
});

it('keeps real bridge errors as failures', function (): void {
    $parsed = BingwaUssdResponse::parseDecodedNativePayload([
        'error' => 'Native USSD bridge is unavailable.',
    ]);

    expect($parsed)->toMatchArray([
        'success' => false,
        'message' => '',
        'bridge_error' => 'Native USSD bridge is unavailable.',
    ]);
});
