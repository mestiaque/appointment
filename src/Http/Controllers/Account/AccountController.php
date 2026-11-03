<?php

namespace ME\Abs\Http\Controllers\Account;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;

/**
 * Customer panel: own bookings only (customer_user_id = the logged-in user).
 */
class AccountController extends Controller
{
    /**
     * "My bookings": one list with tabs. Each row opens the booking's details.
     */
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $tab = in_array($request->query('tab'), ['upcoming', 'past', 'cancelled'], true) ? $request->query('tab') : 'all';
        $now = CarbonImmutable::now();
        $active = [AppointmentStatus::AwaitingPayment->value, AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value, AppointmentStatus::InProgress->value];
        $closed = [AppointmentStatus::Cancelled->value, AppointmentStatus::Rejected->value, AppointmentStatus::Expired->value];

        $base = fn () => Appointment::query()->where('customer_user_id', $user->getKey());
        $query = $base()
            ->with('business:id,name', 'items:id,appointment_id,service_name,duration_minutes', 'staff:id,name', 'branch:id,name')
            ->when($tab === 'upcoming', fn ($q) => $q->whereIn('status', $active)->where('ends_at', '>', $now))
            ->when($tab === 'past', fn ($q) => $q->whereNotIn('status', $closed)->where('ends_at', '<=', $now))
            ->when($tab === 'cancelled', fn ($q) => $q->whereIn('status', $closed));

        // Upcoming first (soonest on top), then the past (latest on top).
        $bookings = $tab === 'upcoming'
            ? $query->orderBy('starts_at')->paginate(15)->withQueryString()
            : $query->orderByRaw('CASE WHEN ends_at > ? THEN 0 ELSE 1 END', [$now])->orderByRaw('CASE WHEN ends_at > ? THEN starts_at END ASC', [$now])->orderByDesc('starts_at')->paginate(15)->withQueryString();

        return view('abs::account.dashboard', [
            'user' => $user,
            'tab' => $tab,
            'bookings' => $bookings,
            'counts' => [
                'all' => $base()->count(),
                'upcoming' => $base()->whereIn('status', $active)->where('ends_at', '>', $now)->count(),
                'past' => $base()->whereNotIn('status', $closed)->where('ends_at', '<=', $now)->count(),
                'cancelled' => $base()->whereIn('status', $closed)->count(),
            ],
        ]);
    }

    /**
     * Details of one of the customer's own bookings (the full booking page: payment, documents, print, changes).
     */
    public function show(Request $request, string $reference): RedirectResponse
    {
        $appointment = Appointment::query()->where('booking_reference', $reference)
            ->where('customer_user_id', $request->user()->getKey())->firstOrFail();

        return redirect()->to(URL::temporarySignedRoute('abs.public.manage', now()->addHours(2), ['reference' => $appointment->booking_reference]));
    }

    public function profile(Request $request): View
    {
        return view('abs::account.profile', ['user' => $request->user()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email:filter', 'max:150', Rule::unique('users', 'email')->ignore($user->getKey())],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]{6,40}$/'],
        ]);

        $user->update(['name' => $data['name'], 'email' => mb_strtolower($data['email']), 'phone' => $data['phone'] ?? null]);

        return back()->with('success', 'Profile updated.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user()->update(['password' => Hash::make($request->input('password'))]);

        return back()->with('success', 'Password changed.');
    }
}
