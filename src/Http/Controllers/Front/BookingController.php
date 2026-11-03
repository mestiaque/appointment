<?php

namespace ME\Abs\Http\Controllers\Front;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use ME\Abs\Actions\AppointmentCancellationAction;
use ME\Abs\Actions\AppointmentReschedulingAction;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\PaymentRecordStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Business;
use ME\Abs\Models\Payment;
use ME\Abs\Payments\CheckoutStatus;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Services\Payments\CheckoutService;
use ME\Abs\Services\Payments\PaymentLedger;

class BookingController extends Controller
{
    public function directory(): View|RedirectResponse
    {
        $businesses = Business::query()->active()->orderBy('name')->get(['id', 'name', 'slug', 'description', 'address']);

        if ($businesses->count() === 1) {
            return redirect()->route('abs.public.show', $businesses->first()->slug);
        }

        return view('abs::public.directory', ['businesses' => $businesses]);
    }

    public function show(string $slug): View
    {
        $business = Business::query()->active()->where('slug', $slug)->firstOrFail();

        return view('abs::public.book', ['business' => $business]);
    }

    /**
     * Old "my bookings" URL: the customer panel replaces it.
     */
    public function mine(): RedirectResponse
    {
        return redirect()->route('abs.account.dashboard');
    }

    public function manage(string $reference, AppointmentCancellationAction $cancellation, AppointmentReschedulingAction $rescheduling, GatewayManager $gateways): View
    {
        $appointment = $this->find($reference);

        $mayReschedule = true;
        try {
            if (! in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
                throw new BookingException('');
            }
            $rescheduling->assertCustomerMayReschedule($appointment);
        } catch (BookingException) {
            $mayReschedule = false;
        }

        return view('abs::public.manage', [
            'appointment' => $appointment,
            'mayCancel' => $cancellation->customerMayCancel($appointment),
            'mayReschedule' => $mayReschedule,
            // Any unpaid balance can be paid online whenever a gateway is configured, whatever the booking-time choice was.
            'gateways' => $online = $gateways->forBusiness($appointment->business),
            'onlineAvailable' => $online->isNotEmpty(),
            'mayPay' => $online->isNotEmpty() && $appointment->outstandingMinor() > 0
                && in_array($appointment->status, [AppointmentStatus::AwaitingPayment, AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn], true),
            'documents' => $appointment->getMedia('documents'),
            'printUrl' => URL::temporarySignedRoute('abs.public.print', now()->addHours(2), ['reference' => $appointment->booking_reference]),
        ]);
    }

    /**
     * Printable booking slip (A4/receipt friendly).
     */
    public function printSlip(string $reference): View
    {
        return view('abs::public.print', ['appointment' => $this->find($reference)]);
    }

    public function cancel(Request $request, string $reference, AppointmentCancellationAction $cancellation): RedirectResponse
    {
        $appointment = $this->find($reference);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        try {
            $cancellation->execute($appointment, ActorType::Customer, $request->user(), $data['reason'] ?? 'Cancelled by customer');
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Your booking has been cancelled.');
    }

    public function pay(Request $request, string $reference, CheckoutService $checkout): RedirectResponse
    {
        $appointment = $this->find($reference);
        $provider = $request->string('provider')->toString() ?: null;

        try {
            $payment = $checkout->start($appointment, $provider);
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->away($payment->checkout_url);
    }

    /**
     * Back from bKash / Nagad / SSLCommerz / PayPal / Stripe. Confirms the payment by asking the provider
     * (never from the redirect itself), then shows the booking page.
     */
    public function paymentCallback(int $payment, string $token, GatewayManager $gateways, PaymentLedger $ledger): RedirectResponse
    {
        $record = Payment::query()->with('appointment.business')->findOrFail($payment);
        abort_unless(hash_equals($record->callbackToken(), $token), 404);
        $appointment = $record->appointment;
        $link = URL::temporarySignedRoute('abs.public.manage', now()->addHours(2), ['reference' => $appointment->booking_reference]);

        $gateway = $gateways->driver($record->provider, $appointment->business);
        if (! $gateway?->capturesOnReturn() || ! $record->provider_reference || $record->status !== PaymentRecordStatus::Pending) {
            return redirect()->to($link)->with('success', $record->status === PaymentRecordStatus::Succeeded
                ? 'Payment received. Thank you!' : 'Thank you. Your payment is being confirmed.');
        }

        try {
            $status = $gateway->retrieveCheckout($record->provider_reference);
        } catch (PaymentException $e) {
            return redirect()->to($link)->with('error', $e->getMessage());
        }

        if ($status->isPaid()) {
            $ledger->markSucceeded($record, $status, 'return');
            $record->refresh();

            return $record->status === PaymentRecordStatus::Succeeded
                ? redirect()->to($link)->with('success', 'Payment received. Thank you!')
                : redirect()->to($link)->with('error', 'The payment could not be matched to this booking. The business will review it.');
        }

        if (in_array($status->state, [CheckoutStatus::FAILED, CheckoutStatus::EXPIRED], true)) {
            $ledger->markFailed($record, 'Payment was not completed at the provider', 'return');

            return redirect()->to($link)->with('error', 'The payment was not completed. No money was taken — you can try again.');
        }

        return redirect()->to($link)->with('success', 'Your payment is being confirmed. Refresh this page in a moment.');
    }

    /**
     * Customer is back from the provider. This page never marks anything paid — only the verified webhook does.
     */
    public function paymentReturn(string $reference, int $payment, GatewayManager $gateways, PaymentLedger $ledger): RedirectResponse
    {
        $appointment = $this->find($reference);
        $record = $appointment->payments()->whereKey($payment)->firstOrFail();

        // Providers that need a capture (PayPal) are confirmed here by a server-to-server call — still never
        // by the redirect itself. Amount and currency are checked by the ledger.
        $gateway = $gateways->driver($record->provider, $appointment->business);
        if ($gateway?->capturesOnReturn() && $record->provider_reference && $record->status === PaymentRecordStatus::Pending) {
            try {
                $status = $gateway->retrieveCheckout($record->provider_reference);
                if ($status->isPaid()) {
                    $ledger->markSucceeded($record, $status, 'return');

                    return redirect()->to($appointment->manageUrl())->with('success', 'Thank you. Your payment was received.');
                }
            } catch (PaymentException $e) {
                return redirect()->to($appointment->manageUrl())->with('error', $e->getMessage());
            }
        }

        return redirect()->to($appointment->manageUrl())->with('success', 'Thank you. Your payment is being confirmed — this page updates once the payment provider confirms it.');
    }

    private function find(string $reference): Appointment
    {
        return Appointment::query()
            ->where('booking_reference', $reference)
            ->with('business', 'items', 'staff:id,name', 'branch:id,name,address', 'payments')
            ->firstOrFail();
    }
}
