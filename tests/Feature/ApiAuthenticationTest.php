<?php

use App\Models\User;

it('issues a sanctum token with valid credentials', function () {
    $user = User::factory()->create([
        'email' => 'api@example.com',
        'password' => 'password',
    ]);

    $response = $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertExactJson([
            'token' => $response->json('token'),
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ]);

    expect($user->fresh()->tokens)->toHaveCount(1);
});

it('rejects invalid API credentials', function () {
    $user = User::factory()->create();

    $this->postJson('/api/login', [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertUnprocessable()
        ->assertJson([
            'message' => 'The provided credentials are incorrect.',
        ]);
});

it('revokes the current sanctum token on logout', function () {
    $user = User::factory()->create();
    $token = $user->createToken('Postman');

    $this->withToken($token->plainTextToken)
        ->postJson('/api/logout')
        ->assertOk();

    expect($user->fresh()->tokens)->toBeEmpty();
});
