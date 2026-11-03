<?php

namespace ME\Abs\Http\Controllers\Front;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use ME\Abs\Models\Appointment;

/**
 * "Find my booking": booking number + the phone number on the booking.
 * When metheme SMS is enabled, a 6-digit code is also sent to that phone.
 * Success gives a 2-hour signed link to the booking page (details, documents, payment).
 * Failures never reveal whether a booking number exists.
 */
class LookupController extends Controller
{
    private const CODE_TTL_MINUTES = 10;

    private const LINK_TTL_MINUTES = 120;

    public function show(Request $request): View
    {
        return view('abs::public.find', [
            'pending' => $request->session()->get('abs.lookup'),
            'smsEnabled' => $this->smsEnabled(),
        ]);
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:20'],
            'phone' => ['required', 'string', 'max:40'],
        ]);
        $reference = strtoupper(trim($data['reference']));
        $reference = str_starts_with($reference, 'BK-') ? $reference : 'BK-'.$reference;
        $this->throttle('abs-lookup:'.$request->ip(), 10);
        $this->throttle('abs-lookup-ref:'.$reference, 5);

        $appointment = Appointment::query()->where('booking_reference', $reference)->first();
        if (! $appointment || ! self::phonesMatch($appointment->customer_phone, $data['phone'])) {
            RateLimiter::hit('abs-lookup:'.$request->ip(), 900);
            RateLimiter::hit('abs-lookup-ref:'.$reference, 900);
            throw ValidationException::withMessages(['reference' => 'We could not find a booking with that number and phone. Please check both.']);
        }

        if (! $this->smsEnabled()) {
            return redirect()->to($this->link($appointment));
        }

        $code = (string) random_int(100000, 999999);
        Cache::put($this->cacheKey($reference), ['hash' => hash('sha256', $code), 'attempts' => 0], now()->addMinutes(self::CODE_TTL_MINUTES));
        $request->session()->put('abs.lookup', ['reference' => $reference, 'phone' => self::mask($appointment->customer_phone)]);

        $sent = me_sms($appointment->customer_phone, "Your {$appointment->business->name} verification code is {$code}. It expires in ".self::CODE_TTL_MINUTES.' minutes.', hideMessage: true);
        if (! $sent) {
            Log::warning('abs: lookup verification SMS failed', ['reference' => $reference]);

            return back()->with('error', 'We could not send the verification SMS right now. Please try again later.');
        }

        return redirect()->route('abs.public.find')->with('success', 'We sent a 6-digit code to '.self::mask($appointment->customer_phone).'.');
    }

    public function verify(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('abs.lookup');
        abort_unless($pending, 419);
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $this->throttle('abs-lookup:'.$request->ip(), 10);

        $key = $this->cacheKey($pending['reference']);
        $stored = Cache::get($key);
        if (! $stored || $stored['attempts'] >= 5 || ! hash_equals($stored['hash'], hash('sha256', $data['code']))) {
            if ($stored) {
                $stored['attempts']++;
                Cache::put($key, $stored, now()->addMinutes(self::CODE_TTL_MINUTES));
            }
            RateLimiter::hit('abs-lookup:'.$request->ip(), 900);
            throw ValidationException::withMessages(['code' => $stored && $stored['attempts'] < 5 ? 'That code is not correct.' : 'This code expired. Please start again.']);
        }

        Cache::forget($key);
        $request->session()->forget('abs.lookup');

        return redirect()->to($this->link(Appointment::query()->where('booking_reference', $pending['reference'])->firstOrFail()));
    }

    public function restart(Request $request): RedirectResponse
    {
        $request->session()->forget('abs.lookup');

        return redirect()->route('abs.public.find');
    }

    /**
     * Same number when the last 10 digits match (ignores spaces, dashes and country code).
     */
    public static function phonesMatch(?string $stored, string $given): bool
    {
        $a = preg_replace('/\D+/', '', (string) $stored);
        $b = preg_replace('/\D+/', '', $given);

        return strlen($a) >= 7 && strlen($b) >= 7 && hash_equals(substr($a, -10), substr($b, -10));
    }

    public static function mask(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        return str_repeat('•', max(0, strlen($digits) - 3)).substr($digits, -3);
    }

    private function link(Appointment $appointment): string
    {
        return URL::temporarySignedRoute('abs.public.manage', now()->addMinutes(self::LINK_TTL_MINUTES), ['reference' => $appointment->booking_reference]);
    }

    private function throttle(string $key, int $max): void
    {
        if (RateLimiter::tooManyAttempts($key, $max)) {
            throw ValidationException::withMessages(['reference' => 'Too many attempts. Please try again in '.ceil(RateLimiter::availableIn($key) / 60).' minutes.']);
        }
    }

    private function cacheKey(string $reference): string
    {
        return 'abs:lookup-code:'.$reference;
    }

    private function smsEnabled(): bool
    {
        return (bool) config('services.sms_enabled') && function_exists('me_sms');
    }
}
