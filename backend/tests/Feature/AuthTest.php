<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_a_user_and_returns_a_sanctum_token(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'token',
                'user' => ['id', 'name', 'email'],
            ])
            ->assertJsonMissingPath('user.balance');

        $user = User::query()->where('email', 'new-user@example.test')->firstOrFail();

        $this->assertSame('0.00000000', $user->balance);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'name' => 'api',
        ]);
    }

    public function test_registration_validates_required_fields_and_password_confirmation(): void
    {
        $this->postJson('/api/register', [
            'name' => 'New User',
            'email' => 'new-user@example.test',
            'password' => 'password123',
            'password_confirmation' => 'different-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_login_returns_a_token_for_valid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.test',
            'password' => 'password123',
        ])->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email']]);
    }

    public function test_login_rejects_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'login@example.test',
            'password' => 'password123',
        ]);

        $this->postJson('/api/login', [
            'email' => 'login@example.test',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    }

    public function test_login_is_rate_limited(): void
    {
        $credentials = [
            'email' => 'missing@example.test',
            'password' => 'incorrect',
        ];

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/login', $credentials)->assertUnprocessable();
        }

        $this->postJson('/api/login', $credentials)->assertTooManyRequests();
    }

    public function test_logout_revokes_the_current_personal_access_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);

        $this->assertSame(0, PersonalAccessToken::query()->count());
    }

    public function test_logout_requires_authentication(): void
    {
        $this->postJson('/api/logout')->assertUnauthorized();
    }
}
