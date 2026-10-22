<?php

use App\Models\PersonalAccessToken;
use App\Models\User;

use function Pest\Laravel\freezeSecond;

test('sanctum expiry and last used writes are stored on the token', function () {
    freezeSecond();
    $user = User::factory()->create();

    $token = $user->morphMany(PersonalAccessToken::class, 'tokenable')->create([
        'name' => 'test',
        'token' => hash('sha256', 'plain-text-token'),
        'abilities' => ['*'],
        'expires_at' => now()->addDay(),
    ]);
    $token->forceFill(['last_used_at' => now()])->save();

    $token->refresh();
    expect($token->expires_at)->toEqual(now()->addDay())
        ->and($token->last_used_at)->toEqual(now())
        ->and($token->created_at)->toEqual(now());
});
