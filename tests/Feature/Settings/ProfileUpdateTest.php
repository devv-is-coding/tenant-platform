<?php

use App\Models\User;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\assertSoftDeleted;
use function Pest\Laravel\get;

test('profile page is displayed', function () {
    actingAs(User::factory()->create());

    get(route('profile.edit'))->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('first_name', 'Test')
        ->set('middle_name', '')
        ->set('last_name', 'User')
        ->set('username', 'test-user')
        ->set('email', 'test@example.com')
        ->set('contact_number', '09123456789')
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    $user->refresh();

    expect($user->first_name)->toEqual('Test');
    expect($user->middle_name)->toBeNull();
    expect($user->last_name)->toEqual('User');
    expect($user->username)->toEqual('test-user');
    expect($user->email)->toEqual('test@example.com');
    expect($user->contact_number)->toEqual('09123456789');
    expect($user->email_verified_at)->toBeNull();
});

test('profile form is prefilled with the current user details', function () {
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test('pages::settings.profile')
        ->assertSet('first_name', $user->first_name)
        ->assertSet('middle_name', $user->middle_name)
        ->assertSet('last_name', $user->last_name)
        ->assertSet('username', $user->username)
        ->assertSet('email', $user->email)
        ->assertSet('contact_number', $user->contact_number);
});

test('factory usernames satisfy the profile username rules', function () {
    $usernames = User::factory()->count(50)->make()->pluck('username');

    expect($usernames)->each->toMatch('/^[A-Za-z0-9_-]+$/');
});

test('username must be unique when updating the profile', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create();

    actingAs($user);

    Livewire::test('pages::settings.profile')
        ->set('username', 'taken')
        ->call('updateProfileInformation')
        ->assertHasErrors(['username' => 'unique']);
});

test('email verification status is unchanged when email address is unchanged', function () {
    $user = User::factory()->create();

    actingAs($user);

    $response = Livewire::test('pages::settings.profile')
        ->set('first_name', 'Test')
        ->set('email', $user->email)
        ->call('updateProfileInformation');

    $response->assertHasNoErrors();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser');

    $response
        ->assertHasNoErrors()
        ->assertRedirect('/');

    assertSoftDeleted($user);
    assertGuest();
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    actingAs($user);

    $response = Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'wrong-password')
        ->call('deleteUser');

    $response->assertHasErrors(['password']);

    expect($user->fresh())->not->toBeNull();
});
