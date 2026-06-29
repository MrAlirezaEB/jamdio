<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Minimal single-password gate for the admin control panel.
 */
class AdminAuthController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        if ($request->session()->get('is_admin')) {
            return redirect()->route('admin.dashboard');
        }

        return Inertia::render('Admin/Login');
    }

    public function login(Request $request): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $key = 'admin-login:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'password' => 'Too many attempts. Try again in a minute.',
            ]);
        }

        if (! hash_equals((string) config('radio.admin_password'), (string) $request->input('password'))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'password' => 'Incorrect admin password.',
            ]);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('is_admin', true);

        return redirect()->route('admin.dashboard');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('is_admin');

        return redirect()->route('home');
    }
}
