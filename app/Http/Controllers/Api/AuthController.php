<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if (Auth::attempt($request->only('email', 'password'))) {
            $request->session()->regenerate();

            return response()->json([
                'user' => Auth::user(),
                'message' => 'Login successful'
            ]);
        }

        throw ValidationException::withMessages([
            'email' => ['Wrong email or password'],
        ]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return response()->json([
            'user' => $user,
            'message' => 'Registration successful'
        ], 201);
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logged out successfully']);
    }

    public function user(Request $request)
    {
        return response()->json($request->user());
    }

 public function redirectToGoogle(Request $request)
{
    try {
        // ✅ START SESSION FIRST - This is the fix!
        if (!$request->session()->has('_token')) {
            $request->session()->put('_token', csrf_token());
            $request->session()->save();
        }

        // SSL bypass for local dev
        $socialite = Socialite::driver('google')->stateless();

        if (config('app.env') === 'local') {
            $socialite->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
        }

        return $socialite->redirect();
    } catch (\Exception $e) {
        return redirect('http://localhost:5173/login?error=google_auth_failed&message=' . urlencode($e->getMessage()));
    }
}

    public function handleGoogleCallback(Request $request)
    {
        try {
            // 🔧 PRODUCTION: Remove this SSL bypass
            $socialite = Socialite::driver('google')->stateless();

            if (config('app.env') === 'local') {
                $socialite->setHttpClient(new \GuzzleHttp\Client(['verify' => false]));
            }

            $googleUser = $socialite->user();

            $user = User::updateOrCreate(
                ['email' => $googleUser->email],
                [
                    'name' => $googleUser->name ?? 'Google User',
                    'google_id' => $googleUser->id,
                    'avatar' => $googleUser->avatar,
                    'password' => Hash::make(Str::random(24)),
                    'email_verified_at' => now(),
                ]
            );

            Auth::login($user);
            $request->session()->regenerate();

            // 🔧 PRODUCTION: Change this redirect URL
            return redirect('http://localhost:5173/dashboard');

        } catch (\Exception $e) {
            // 🔧 PRODUCTION: Change frontend URL
            return redirect('http://localhost:5173/login?error=google_auth_failed&message=' . urlencode($e->getMessage()));
        }
    }
}
