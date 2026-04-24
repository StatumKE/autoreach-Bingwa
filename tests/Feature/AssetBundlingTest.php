<?php

use Illuminate\Support\Facades\Vite;

test('application shell uses local vite assets', function () {
    $response = $this->get(route('login'));

    $response->assertOk();
    $response->assertDontSee('fonts.bunny.net');
    $response->assertSee(Vite::asset('resources/images/favicon.svg'), false);
    $response->assertSee(Vite::asset('resources/images/apple-touch-icon.png'), false);
});
