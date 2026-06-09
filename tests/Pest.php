<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $function, ?string $payload = null): string
    {
        $GLOBALS['last_nativephp_call'] = [
            'function' => $function,
            'payload' => $payload,
        ];

        if (isset($GLOBALS['nativephp_call_mock'])) {
            return $GLOBALS['nativephp_call_mock']($function, $payload);
        }

        return '{"success":true,"message":"Recommendation submitted successfully."}';
    }
}

if (! function_exists('nativephp_can')) {
    function nativephp_can(string $method): bool
    {
        if (isset($GLOBALS['nativephp_can_mock'])) {
            return $GLOBALS['nativephp_can_mock']($method);
        }

        return true;
    }
}
