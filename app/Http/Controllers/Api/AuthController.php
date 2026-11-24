<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CurrentUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::guard('web')->attempt($request->only('email', 'password'))) {
            throw ValidationException::withMessages(['email' => ['Wrong email or password']]);
        }
        $request->session()->regenerate();

        return response()->json(['user' => (new CurrentUserResource(Auth::guard('web')->user()))->resolve($request), 'message' => 'Login successful']);
    }

    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);
        $user = User::create($validated);
        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => (new CurrentUserResource($user))->resolve($request), 'message' => 'Registration successful'], 201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        Auth::guard('sanctum')->forgetUser();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json((new CurrentUserResource($request->user()))->resolve($request));
    }

    public function redirectToGoogle(Request $request)
    {
        try {
            // Keep the same browser redirect flow, with Socialite's session state protection.
            return Socialite::driver('google')->redirect();
        } catch (\Exception $e) {
            return $this->googleFailure($e);
        }
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
            $raw = $googleUser->getRaw();
            if (($raw['email_verified'] ?? null) !== true
                || ! is_string($googleUser->getEmail())
                || ! filter_var($googleUser->getEmail(), FILTER_VALIDATE_EMAIL)
                || ! is_string($googleUser->getId()) || $googleUser->getId() === '') {
                throw new \UnexpectedValueException('Google did not provide a verified identity.');
            }

            $user = DB::transaction(function () use ($googleUser) {
                // Bind an established provider subject first; do not move its email or identity.
                $matches = User::where('google_id', $googleUser->getId())->lockForUpdate()->get();
                if ($matches->count() > 1) {
                    throw new \UnexpectedValueException('Ambiguous Google identity.');
                }
                $user = $matches->first();
                if (! $user) {
                    $user = User::where('email', $googleUser->getEmail())->lockForUpdate()->first();
                    if ($user && $user->google_id !== null && $user->google_id !== $googleUser->getId()) {
                        throw new \UnexpectedValueException('This account has a different Google identity.');
                    }
                }
                if (! $user) {
                    $user = new User([
                        'name' => $googleUser->getName() ?: 'Google User',
                        'email' => $googleUser->getEmail(),
                        'password' => Hash::make(Str::random(64)),
                    ]);
                }
                // These fields are deliberately server assigned, not general mass-assignment inputs.
                $user->forceFill([
                    'google_id' => $googleUser->getId(),
                    'avatar' => $googleUser->getAvatar(),
                    'email_verified_at' => $user->email === $googleUser->getEmail()
                        ? ($user->email_verified_at ?? now()) : $user->email_verified_at,
                ])->save();

                return $user;
            });

            Auth::guard('web')->login($user);
            $request->session()->regenerate();
            $data = urlencode(json_encode((new CurrentUserResource($user))->resolve($request), JSON_THROW_ON_ERROR));

            return redirect(rtrim((string) config('app.frontend_url'), '/').'/auth/google/success?user='.$data);
        } catch (\Exception $e) {
            return $this->googleFailure($e);
        }
    }

    private function googleFailure(\Exception $exception)
    {
        report($exception);

        return redirect(rtrim((string) config('app.frontend_url'), '/').'/login?'.http_build_query([
            'error' => 'google_auth_failed',
            'message' => 'Google sign-in could not be completed. Please try again.',
        ]));
    }
}
