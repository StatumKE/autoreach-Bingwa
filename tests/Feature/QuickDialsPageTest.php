<?php

use App\Actions\QuickDial\QuickDialContacts;
use App\Models\User;
use Livewire\Livewire;

test('quick dial page can be rendered', function () {
    $this->actingAs(User::factory()->create());

    $this->get(route('quick-dials'))
        ->assertOk()
        ->assertSee('Quick Dial')
        ->assertSee('Search contacts from the device');
});

test('quick dial page loads contacts through the device contacts action', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->mock(QuickDialContacts::class, function ($mock): void {
        $mock->shouldReceive('checkPermission')
            ->twice()
            ->andReturn(true);

        $mock->shouldReceive('search')
            ->once()
            ->with('', 20)
            ->andReturn([
                [
                    'name' => 'Jane Doe',
                    'phone' => '+254700000001',
                    'label' => 'Mobile',
                ],
            ]);
    });

    Livewire::test('quick-dials')
        ->call('initializeContacts')
        ->assertSee('Jane Doe')
        ->assertSee('+254700000001')
        ->assertSee('Mobile');
});

test('quick dial reacts to the native permission result event', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    $this->mock(QuickDialContacts::class, function ($mock): void {
        $mock->shouldReceive('checkPermission')
            ->andReturn(false, false, true, true);

        $mock->shouldReceive('requestPermission')
            ->once()
            ->andReturn(true);

        $mock->shouldReceive('search')
            ->once()
            ->with('', 20)
            ->andReturn([
                [
                    'name' => 'Jane Doe',
                    'phone' => '+254700000001',
                    'label' => 'Mobile',
                ],
            ]);
    });

    Livewire::test('quick-dials')
        ->call('initializeContacts')
        ->call('requestContactsPermission')
        ->dispatch('native:onPermissionResult', permission: 'android.permission.READ_CONTACTS', granted: true)
        ->assertSet('contactsPermissionGranted', true)
        ->assertSee('Jane Doe')
        ->assertDontSee('Contacts access is needed to search the phone book.');
});
