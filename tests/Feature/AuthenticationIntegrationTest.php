<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

class AuthenticationIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function googleIdentity(string $email, string $id = 'google-subject', bool $verified = true): GoogleUser
    {
        return (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
            'id' => $id, 'email' => $email, 'name' => 'Google Name',
            'avatar' => 'https://example.com/avatar.png',
        ]);
    }

    private function mockGoogle(GoogleUser $identity): void
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
    }

    public function test_existing_password_account_logs_in_and_current_user_is_explicit(): void
    {
        $user = User::factory()->create(['password' => Hash::make('existing-password')]);
        $this->withHeader('Origin', 'http://localhost:5174')->get('/sanctum/csrf-cookie')
            ->assertNoContent()->assertCookie('XSRF-TOKEN');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/login', ['email' => $user->email, 'password' => 'existing-password'])
            ->assertOk()->assertJsonPath('user.id', $user->id)->assertJsonPath('user.role', 'user')
            ->assertJsonMissingPath('user.password')->assertJsonMissingPath('user.google_id');
        $this->assertAuthenticatedAs($user, 'web');
        $this->getJson('/api/user')->assertOk()->assertJsonStructure([
            'id', 'name', 'email', 'avatar', 'email_verified_at', 'role',
        ])->assertJsonMissingPath('google_id');
        $this->postJson('/api/logout')->assertOk();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_registration_does_not_accept_privileged_profile_attributes(): void
    {
        $this->withHeader('Origin', 'http://localhost:5174')->postJson('/api/register', [
            'name' => 'New User', 'email' => 'new@example.com',
            'password' => 'password-123', 'password_confirmation' => 'password-123',
            'role' => 'admin', 'google_id' => 'attacker', 'email_verified_at' => now(),
        ])->assertCreated()->assertJsonPath('user.role', 'user')->assertJsonPath('user.email_verified_at', null);
        $user = User::whereEmail('new@example.com')->firstOrFail();
        $this->assertNull($user->google_id);
        $this->assertTrue(Hash::check('password-123', $user->password));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_browser_session_mutations_require_csrf(): void
    {
        // Laravel normally bypasses CSRF in tests. Exercise the actual middleware here.
        $this->app['env'] = 'local';
        $payload = ['email' => 'new@example.com', 'name' => 'New', 'password' => 'password-123', 'password_confirmation' => 'password-123'];
        $this->withHeader('Origin', 'http://localhost:5174')->withSession(['_token' => 'csrf-test-token']);
        $this->postJson('/api/register', $payload)->assertStatus(419)
            ->assertJsonPath('message', 'Session expired. Please refresh CSRF protection and try again.');
        $this->assertDatabaseCount('users', 0);
        $this->withHeader('X-CSRF-TOKEN', 'csrf-test-token')->postJson('/api/register', $payload)->assertCreated();
    }

    public function test_google_login_preserves_existing_identity_name_and_password_and_persists_profile(): void
    {
        $user = User::factory()->create(['name' => 'Existing Name']);
        $password = $user->password;
        $this->mockGoogle($this->googleIdentity($user->email));
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $user->refresh();
        $this->assertSame($password, $user->password);
        $this->assertSame('Existing Name', $user->name);
        $this->assertSame('google-subject', $user->google_id);
        $this->assertSame('https://example.com/avatar.png', $user->avatar);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_new_verified_google_account_can_log_in(): void
    {
        $this->mockGoogle($this->googleIdentity('google@example.com'));
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $user = User::whereEmail('google@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('google-subject', $user->google_id);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_known_google_subject_does_not_overwrite_local_email_or_password(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'google-subject'])->save();
        $email = $user->email;
        $password = $user->password;
        $this->mockGoogle($this->googleIdentity('changed@example.com'));
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $this->assertSame($email, $user->fresh()->email);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unverified_google_email_cannot_link_or_create_an_account(): void
    {
        $user = User::factory()->unverified()->create();
        $password = $user->password;
        $this->mockGoogle($this->googleIdentity($user->email, verified: false));
        $this->get('/api/auth/google/callback')->assertRedirectContains('/login?error=google_auth_failed');
        $this->assertNull($user->fresh()->google_id);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame($password, $user->fresh()->password);
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_conflicting_provider_identity_is_rejected_without_modifying_account(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'original-subject'])->save();
        $this->mockGoogle($this->googleIdentity($user->email));
        $this->get('/api/auth/google/callback')->assertRedirectContains('/login?error=google_auth_failed');
        $this->assertSame('original-subject', $user->fresh()->google_id);
        $this->assertGuest('web');
    }

    public function test_google_callback_rejects_missing_oauth_state_before_any_provider_request(): void
    {
        config(['services.google' => [
            'client_id' => 'test-client', 'client_secret' => 'test-secret',
            'redirect' => 'http://localhost:8000/api/auth/google/callback',
        ]]);
        $redirect = $this->get('/api/auth/google');
        $redirect->assertRedirectContains('accounts.google.com');
        parse_str(parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertNotEmpty($query['state']);
        $this->get('/api/auth/google/callback?code=fake&state=incorrect')
            ->assertRedirectContains('/login?error=google_auth_failed');
        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_google_failure_does_not_expose_provider_error_details(): void
    {
        $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
        $provider->shouldReceive('user')->once()->andThrow(new \RuntimeException('private client credential'));
        Socialite::shouldReceive('driver')->with('google')->once()->andReturn($provider);
        $response = $this->get('/api/auth/google/callback')->assertRedirectContains('/login?error=google_auth_failed');
        $this->assertStringNotContainsString('credential', $response->headers->get('Location'));
        $this->assertGuest('web');
    }

    public function test_repeated_google_callbacks_do_not_duplicate_users_or_replace_passwords(): void
    {
        $identity = $this->googleIdentity('repeat@example.com');
        $provider = Mockery::mock(\Laravel\Socialite\Two\GoogleProvider::class);
        $provider->shouldReceive('user')->twice()->andReturn($identity);
        Socialite::shouldReceive('driver')->with('google')->twice()->andReturn($provider);
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $password = User::whereEmail('repeat@example.com')->value('password');
        $this->get('/api/auth/google/callback')->assertRedirectContains('/auth/google/success?user=');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($password, User::whereEmail('repeat@example.com')->value('password'));
    }

    public function test_login_attempts_are_rate_limited(): void
    {
        $this->withHeader('Origin', 'http://localhost:5174');
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong'])
                ->assertUnprocessable();
        }
        $this->postJson('/api/login', ['email' => 'missing@example.com', 'password' => 'wrong'])
            ->assertStatus(429)->assertHeader('Retry-After');
    }
}
