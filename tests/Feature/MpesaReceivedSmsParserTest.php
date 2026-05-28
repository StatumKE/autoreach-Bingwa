<?php

use App\Support\MpesaReceivedSmsParser;

it('parses the standard M-Pesa receive format', function (): void {
    $parsed = app(MpesaReceivedSmsParser::class)->parse(
        'UBO817Y3ZG Confirmed.You have received Ksh99.00 from JOHN DOE 0712345678 on 26/2/26 at 9:40 PM.'
    );

    expect($parsed)->not->toBeNull()
        ->and($parsed->code)->toBe('UBO817Y3ZG')
        ->and($parsed->amount)->toBe('99.00')
        ->and($parsed->senderName)->toBe('JOHN DOE')
        ->and($parsed->senderPhone)->toBe('0712345678')
        ->and($parsed->occurredAt->format('Y-m-d H:i'))->toBe('2026-02-26 21:40');
});

it('parses an arbitrarily long alphanumeric M-Pesa code', function (): void {
    $parsed = app(MpesaReceivedSmsParser::class)->parse(
        'UBO817Y3ZG9X8Q7W6E5R4T3Y2U1I0O9P8 Confirmed.You have received Ksh99.00 from JOHN DOE 0712345678 on 26/2/26 at 9:40 PM.'
    );

    expect($parsed)->not->toBeNull()
        ->and($parsed->code)->toBe('UBO817Y3ZG9X8Q7W6E5R4T3Y2U1I0O9P8')
        ->and($parsed->amount)->toBe('99.00')
        ->and($parsed->senderName)->toBe('JOHN DOE')
        ->and($parsed->senderPhone)->toBe('0712345678')
        ->and($parsed->occurredAt->format('Y-m-d H:i'))->toBe('2026-02-26 21:40');
});

it('parses the standard receive format with a trailing balance sentence', function (): void {
    $parsed = app(MpesaReceivedSmsParser::class)->parse(
        'HNLOAR03 Confirmed.You have received Ksh20.00 from Bob Mwenda 0711663113 on 16/5/26 at 9:40 PM.New M-PESA balance is Ksh5,000.00.'
    );

    expect($parsed)->not->toBeNull()
        ->and($parsed->code)->toBe('HNLOAR03')
        ->and($parsed->amount)->toBe('20.00')
        ->and($parsed->senderName)->toBe('Bob Mwenda')
        ->and($parsed->senderPhone)->toBe('0711663113')
        ->and($parsed->occurredAt->format('Y-m-d H:i'))->toBe('2026-05-16 21:40');
});

it('parses date-first phone-first M-Pesa receive format', function (): void {
    $parsed = app(MpesaReceivedSmsParser::class)->parse(
        'UCRK6AKCLP Confirmed.on 27/3/26 at 11:19 AMKSH55.00 received from 254791705564 JOSEPHINE WANJIKU MUTHIORA. New Account balance is KSH23,884.53. Transaction cost, KSH0.00.'
    );

    expect($parsed)->not->toBeNull()
        ->and($parsed->code)->toBe('UCRK6AKCLP')
        ->and($parsed->amount)->toBe('55.00')
        ->and($parsed->senderName)->toBe('JOSEPHINE WANJIKU MUTHIORA')
        ->and($parsed->senderPhone)->toBe('0791705564')
        ->and($parsed->occurredAt->format('Y-m-d H:i'))->toBe('2026-03-27 11:19');
});

it('parses date-first M-Pesa SMS with a 01XXXXXXXX sender number', function (): void {
    $parsed = app(MpesaReceivedSmsParser::class)->parse(
        'UEHAR4H11X Confirmed.on 17/5/26 at 7:38 PMKSH10.00 received from 254118959014 GLADys JEBIWOTT KIBET. New Account balance is KSH114,405.52. Transaction cst, KSH0.00.'
    );

    expect($parsed)->not->toBeNull()
        ->and($parsed->code)->toBe('UEHAR4H11X')
        ->and($parsed->amount)->toBe('10.00')
        ->and($parsed->senderName)->toBe('GLADys JEBIWOTT KIBET')
        ->and($parsed->senderPhone)->toBe('0118959014')
        ->and($parsed->occurredAt->format('Y-m-d H:i'))->toBe('2026-05-17 19:38');
});

it('rejects unsupported bodies invalid phones and invalid dates', function (): void {
    $parser = app(MpesaReceivedSmsParser::class);

    expect($parser->parse('Your account balance is KSH 100.'))->toBeNull()
        ->and($parser->parse('UBO817Y3ZG Confirmed.You have received Ksh99.00 from JOHN DOE 0612345678 on 26/2/26 at 9:40 PM.'))->toBeNull()
        ->and($parser->parse('UBO817Y3ZG Confirmed.You have received Ksh99.00 from JOHN DOE 0712345678 on 31/2/26 at 9:40 PM.'))->toBeNull();
});

it('trusts only M-Pesa sender IDs unless allow all senders is enabled', function (): void {
    $parser = app(MpesaReceivedSmsParser::class);

    expect($parser->isTrustedSender('MPESA'))->toBeTrue()
        ->and($parser->isTrustedSender('M-PESA'))->toBeTrue()
        ->and($parser->isTrustedSender('SAFARICOM'))->toBeFalse()
        ->and($parser->isTrustedSender('SAFARICOM', allowAllSenders: true))->toBeTrue();
});
