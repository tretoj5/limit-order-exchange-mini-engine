<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_returns_the_authenticated_user_with_fixed_scale_strings(): void
    {
        $user = User::factory()->create([
            'name' => 'Profile User',
            'email' => 'profile@example.test',
        ]);
        $user->balance = '123.45000000';
        $user->save();

        $user->assets()->create([
            'symbol' => 'ETH',
            'amount' => '2.50000000',
            'locked_amount' => '0.00000000',
        ]);
        $user->assets()->create([
            'symbol' => 'BTC',
            'amount' => '0.12500000',
            'locked_amount' => '0.02500000',
        ]);

        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/api/profile')
            ->assertOk()
            ->assertExactJson([
                'id' => $user->id,
                'name' => 'Profile User',
                'email' => 'profile@example.test',
                'balance' => '123.45000000',
                'assets' => [
                    [
                        'symbol' => 'BTC',
                        'amount' => '0.12500000',
                        'locked_amount' => '0.02500000',
                    ],
                    [
                        'symbol' => 'ETH',
                        'amount' => '2.50000000',
                        'locked_amount' => '0.00000000',
                    ],
                ],
            ]);
    }

    public function test_profile_requires_authentication(): void
    {
        $this->getJson('/api/profile')->assertUnauthorized();
    }
}
