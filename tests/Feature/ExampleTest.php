<?php

test('home route redirects mobile users into the app shell', function () {
    $this->get(route('home'))
        ->assertRedirect(route('login'));
});
