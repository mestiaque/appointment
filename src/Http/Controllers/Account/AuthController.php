<?php

namespace ME\Abs\Http\Controllers\Account;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use ME\Abs\Support\CustomerAccount;
use ME\Models\User;

/**
 * Customer login / registration, separate from metheme's admin login.
 */
class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('abs::account.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $key = 'abs-account-login:'.Str::lower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.RateLimiter::availableIn($key).' seconds.']);
        }

        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => 1], $request->boolean('remember'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => 'These details do not match our records.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->to($this->safeIntended($request));
    }

    public function showRegister(): View
    {
        return view('abs::account.register');
    }

    public function register(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:filter', 'max:150', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]{6,40}$/'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user = User::query()->create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'phone' => $data['phone'] ?? null,
            'password' => $data['password'],
            'is_active' => 1,
        ]);
        $user->roles()->attach(CustomerAccount::role()->getKey());

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->to($this->safeIntended($request))->with('success', 'Welcome, '.$user->name.'! Your account is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('abs.account.login')->with('success', 'You have been signed out.');
    }

    /**
     * Only customer-side pages are valid targets; an old admin link must never be the destination.
     */
    private function safeIntended(Request $request): string
    {
        $intended = (string) $request->session()->pull('url.intended', '');
        $allowed = [url(config('abs.account_path', 'account')), url(config('abs.public_path', 'book'))];

        foreach ($allowed as $base) {
            if ($intended === $base || str_starts_with($intended, $base.'/') || str_starts_with($intended, $base.'?')) {
                return $intended;
            }
        }

        return route('abs.account.dashboard');
    }
}
