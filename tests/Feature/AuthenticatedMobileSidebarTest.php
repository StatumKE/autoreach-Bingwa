<?php

use App\Models\User;

test('authenticated mobile header renders a real flux sidebar toggle', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-flux-sidebar-toggle', false)
        ->assertSee('aria-label="Toggle sidebar"', false)
        ->assertDontSee('alert(\'clicked\')', false)
        ->assertDontSee('pointer-events-none" style="height: calc(112px + var(--inset-top, 0px)); padding-top: var(--inset-top, 0px);"', false);
});
