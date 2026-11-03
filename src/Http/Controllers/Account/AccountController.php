<?php

namespace ME\Abs\Http\Controllers\Account;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;

/**
 * Customer panel: own bookings only (customer_user_id = the logged-in user).
 */
class AccountController extends Controller
{
    public function dashboard(Request $request): View
    {
        $user = $request->user();
        $mine = fn () => Appointment::query()->where('customer_user_id', $user->getKey())
            ->with('business:id,name,slug,timezone', 'items:id,appointment_id,service_name,duration_minutes', 'staff:id,name,title', 'branch:id,name,address');
        $active = [AppointmentStatus::AwaitingPayment->value, AppointmentStatus::Pending->value, AppointmentStatus::Confirmed->value, AppointmentStatus::CheckedIn->value, AppointmentStatus::InProgress->value];

        $upcoming = $mine()->whereIn('status', $active)->where('ends_at', '>', CarbonImmutable::now())->orderBy('starts_at')->limit(20)->get();
        $history = $mine()->where(fn ($q) => $q->whereNotIn('status', $active)->orWhere('ends_at', '<=', CarbonImmutable::now()))
            ->latest('starts_at')->paginate(10);

        $mineIds = Appointment::query()->where('customer_user_id', $user->getKey())->select('id');

        return view('abs::account.dashboard', [
            'user' => $user,
            'next' => $upcoming->first(),
            'upcoming' => $upcoming->slice(1)->values(),
            'history' => $history,
            'stats' => [
                'upcoming' => $upcoming->count(),
                'visits' => Appointment::query()->where('customer_user_id', $user->getKey())->where('status', AppointmentStatus::Completed->value)->count(),
                'businesses' => Appointment::query()->where('customer_user_id', $user->getKey())->distinct()->count('business_id'),
            ],
            // Unpaid balances of active bookings.
            'due' => $upcoming->filter(fn ($a) => $a->outstandingMinor() > 0)->values(),
            // Prescriptions and reports shared on any of the customer's bookings.
            'documents' => \ME\Models\Media::query()
                ->where('mediable_type', (new Appointment)->getMorphClass())
                ->whereIn('mediable_id', $mineIds)
                ->where('collection', 'documents')
                ->latest('id')->limit(6)->get(),
            // "Book again": the same service with the same person, most recent first.
            'again' => Appointment::query()->where('customer_user_id', $user->getKey())
                ->with('business:id,name,slug', 'staff:id,name,title', 'items:id,appointment_id,service_name')
                ->latest('starts_at')->limit(30)->get()
                ->unique(fn ($a) => $a->staff_id.'-'.$a->service_id)->take(4)->values(),
        ]);
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
