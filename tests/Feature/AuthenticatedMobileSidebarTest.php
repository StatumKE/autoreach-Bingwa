<?php

use App\Models\User;

test('authenticated mobile header renders a real flux sidebar toggle', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('app-mobile-menu-toggle', false)
        ->assertSee('aria-label="Open navigation menu"', false)
        ->assertDontSee('alert(\'clicked\')', false)
        ->assertDontSee('pointer-events-none" style="height: calc(112px + var(--inset-top, 0px)); padding-top: var(--inset-top, 0px);"', false);
});
