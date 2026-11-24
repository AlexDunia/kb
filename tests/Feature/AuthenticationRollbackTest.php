<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class AuthenticationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_login_and_logout_use_the_existing_session_contract(): void
    {
        $user = User::factory()->create(['password' => Hash::make('existing-password')]);
        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Origin', 'http://localhost:5174');
        $this->get('/sanctum/csrf-cookie')->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'existing-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonMissingPath('user.password');
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_registration_validates_input_and_starts_a_session(): void
    {
        $this->withHeader('Origin', 'http://localhost:5174');
        $this->postJson('/api/register', ['email' => 'invalid'])->assertUnprocessable();
        $payload = ['name' => 'Existing flow', 'email' => 'registration@example.com',
            'password' => 'password-123', 'password_confirmation' => 'password-123', 'role' => 'admin'];
        $this->postJson('/api/register', $payload)->assertCreated()->assertJsonMissingPath('user.password');
        $user = User::whereEmail($payload['email'])->firstOrFail();
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertNotSame('admin', $user->role);
        $this->assertAuthenticatedAs($user);
        $this->postJson('/api/register', $payload)->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_restored_google_callback_establishes_a_session(): void
    {
        $identity = (new GoogleUser)->map(['id' => 'google-subject', 'email' => 'google@example.com',
            'name' => 'Google User', 'avatar' => 'https://example.com/avatar.png']);
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->once()->andReturnSelf();
        $provider->shouldReceive('user')->once()->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $user = User::whereEmail('google@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->getJson('/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }
}
