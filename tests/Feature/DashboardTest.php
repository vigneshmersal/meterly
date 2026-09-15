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
        ->assertSee('data-testid="current-cycle-card"', false)
        ->assertSee('border-left: 4px solid #2563eb', false)
        ->assertSee('data-testid="projected-overage-card"', false)
        ->assertSee('border-left: 4px solid #f97316', false)
        ->assertSee('data-testid="active-plan-card"', false)
        ->assertSee('border-left: 4px solid #16a34a', false)
        ->assertSee('data-testid="churn-risk-card"', false)
        ->assertSee('background-color: #fef2f2', false)
        ->assertSee('System status (informational)')
        ->assertSee('data-testid="system-status-card"', false)
        ->assertSee('background-color: #eff6ff', false)
        ->assertSee('Daily usage trend');
});
