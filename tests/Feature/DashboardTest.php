<?php

use App\Models\Merchant;
use App\Models\User;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertOk();
});

test('the dashboard renders merchant metrics for the authenticated merchant', function () {
    $merchant = Merchant::factory()->create(['name' => 'Demo Merchant']);
    $user = User::factory()->for($merchant)->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Demo Merchant Dashboard')
        ->assertSee('Current cycle usage')
        ->assertSee('No active plan');
});
