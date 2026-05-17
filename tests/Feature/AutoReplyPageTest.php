<?php

use App\Models\AutoReply;
use App\Models\User;
use Livewire\Livewire;

test('guests are redirected from the auto replies page', function () {
    $response = $this->get(route('auto-replies'));

    $response->assertRedirect(route('login'));
});

test('auto replies page creates default replies on first visit', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('auto-replies'));

    $response->assertOk();
    $response->assertSee('Auto Replies');
    $response->assertSee('Successful Response');

    $this->assertDatabaseHas('auto_replies', [
        'user_id' => $user->id,
        'trigger_condition' => 'successful_transaction',
        'is_default' => true,
    ]);

    $this->assertDatabaseHas('auto_replies', [
        'user_id' => $user->id,
        'trigger_condition' => 'failed_transaction',
        'is_default' => true,
    ]);
});

test('auto replies page does not duplicate default replies on repeat visits', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->get(route('auto-replies'))->assertOk();
    $this->get(route('auto-replies'))->assertOk();

    expect(AutoReply::query()->where('user_id', $user->id)->count())->toBe(6);
});

test('auto replies can be created from the page', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = Livewire::test('auto-replies')
        ->call('createAutoReply')
        ->assertDispatched('modal-show', name: 'auto-reply-form')
        ->set('name', 'Custom Success Reply')
        ->set('triggerCondition', 'successful_transaction')
        ->set('replyMessage', 'Hi <firstName>, thank you for purchasing from Bingwa Hybrid.')
        ->set('isActive', true)
        ->call('saveAutoReply');

    $response->assertHasNoErrors();
    $response->assertSet('showForm', false);

    $this->assertDatabaseHas('auto_replies', [
        'user_id' => $user->id,
        'name' => 'Custom Success Reply',
        'trigger_condition' => 'successful_transaction',
        'is_active' => true,
    ]);
});

test('auto replies can be toggled from the page', function () {
    $user = User::factory()->create();
    $autoReply = AutoReply::factory()->for($user)->create([
        'name' => 'Manual Reply',
        'trigger_condition' => 'app_paused',
        'reply_message' => 'System paused.',
        'is_active' => false,
    ]);

    $this->actingAs($user);

    $response = Livewire::test('auto-replies')
        ->call('toggleAutoReply', $autoReply->id);

    $response->assertHasNoErrors();

    $this->assertDatabaseHas('auto_replies', [
        'id' => $autoReply->id,
        'is_active' => true,
    ]);
});

test('only one active auto reply per trigger remains enabled', function () {
    $user = User::factory()->create();

    $activeReply = AutoReply::factory()->for($user)->create([
        'name' => 'Old Success Reply',
        'trigger_condition' => 'successful_transaction',
        'reply_message' => 'Old success reply.',
        'is_active' => true,
    ]);

    $replacementReply = AutoReply::factory()->for($user)->create([
        'name' => 'Replacement Success Reply',
        'trigger_condition' => 'successful_transaction',
        'reply_message' => 'Replacement success reply.',
        'is_active' => false,
    ]);

    $this->actingAs($user);

    Livewire::test('auto-replies')
        ->call('toggleAutoReply', $replacementReply->id)
        ->assertHasNoErrors();

    expect($activeReply->fresh()->is_active)->toBeFalse();
    expect($replacementReply->fresh()->is_active)->toBeTrue();
});
