<?php

namespace ME\Abs\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Enums\ApprovalMode;
use ME\Abs\Enums\PaymentMode;
use ME\Abs\Enums\PaymentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Models\Appointment;
use ME\Abs\Models\Staff;
use ME\Abs\Services\Booking\AvailabilityService;
use ME\Abs\Services\Booking\BookingEligibilityService;
use ME\Abs\Services\Booking\BookingReferenceGenerator;
use ME\Abs\Services\Booking\BookingRequest;
use ME\Abs\Services\Booking\BookingWindow;
use ME\Abs\Services\Booking\PriceCalculator;
use ME\Abs\Services\Booking\ServiceOffer;
use ME\Abs\Services\Notifications\AppointmentNotifier;

/**
 * Creates an appointment.
 *
 * Concurrency: inside one transaction the staff row is locked (SELECT ... FOR UPDATE), then availability is
 * checked again with locking reads, then the appointment is inserted. A second request for the same staff waits
 * on the lock and sees the first booking once it commits. For "any staff", candidates are tried in ascending id
 * order, so two transactions never lock staff rows in opposite orders. Deadlocks are retried (3 attempts).
 */
class AppointmentBookingAction
{
    public function __construct(
        private BookingEligibilityService $eligibility,
        private AvailabilityService $availability,
        private PriceCalculator $prices,
        private BookingReferenceGenerator $references,
        private AppointmentStateMachine $stateMachine,
        private AppointmentNotifier $notifier,
    ) {}

    public function execute(BookingRequest $request): Appointment
    {
        $business = $request->business;
        $settings = $business->settings();
        $this->eligibility->assertBusinessBookable($business);

        if (! $request->isStaffBooking() && ! $request->customer && ! $settings->allow_guest_booking) {
            throw new BookingException('Please sign in to book an appointment.');
        }

        $start = $request->startsAt->utc()->startOfMinute();
        if (! $request->isStaffBooking() && ! BookingWindow::for($settings)->allows($start)) {
            throw new BookingException('That time is outside the booking window for this business.');
        }

        $branch = $this->eligibility->resolveBranch($business, $request->branchId);
        $service = $this->eligibility->resolveService($business, $request->serviceId);
        $offers = $request->staffId
            ? collect([$this->eligibility->offer($business, $service, $request->staffId, $branch)])
            : $this->eligibility->eligibleOffers($business, $service, $branch);

        if ($offers->isEmpty()) {
            throw new BookingException('No staff member currently offers this service.');
        }

        $paymentMode = $settings->payment_mode;
        $mustPayOnline = ! $request->isStaffBooking()
            && in_array($paymentMode, [PaymentMode::OnlineRequired, PaymentMode::DepositRequired], true);
        $payOnline = ! $request->isStaffBooking() && ($mustPayOnline || ($request->payOnline && $paymentMode === PaymentMode::OnlineOptional));

        $appointment = DB::transaction(function () use ($request, $offers, $branch, $start, $settings, $payOnline, $paymentMode) {
            foreach ($offers as $offer) {
                /** @var ServiceOffer $offer */
                Staff::query()->whereKey($offer->staff->getKey())->lockForUpdate()->first();

                $free = $this->availability->isAvailable(
                    $request->business,
                    $offer->staff,
                    $offer->serviceInterval($start),
                    $offer->blockedInterval($start),
                    $branch?->getKey(),
                    lockAppointments: true,
                );

                if ($free) {
                    return $this->create($request, $offer, $branch?->getKey(), $start, $settings, $payOnline, $paymentMode);
                }
            }

            throw BookingException::slotUnavailable();
        }, config('abs.transaction_attempts', 3));

        $this->notifier->bookedAfterCommit($appointment);

        return $appointment;
    }

    private function create(BookingRequest $request, ServiceOffer $offer, ?int $branchId, CarbonImmutable $start, $settings, bool $payOnline, PaymentMode $paymentMode): Appointment
    {
        $quote = $this->prices->quote($offer, $settings);
        $deposit = $paymentMode === PaymentMode::DepositRequired ? $quote->depositMinor : 0;
        $onlineAmountDue = $paymentMode === PaymentMode::DepositRequired ? $deposit : $quote->totalMinor;
        $needsHold = $payOnline && $onlineAmountDue > 0;

        $status = match (true) {
            $request->isStaffBooking() => AppointmentStatus::Confirmed,
            $needsHold => AppointmentStatus::AwaitingPayment,
            $settings->approval_mode === ApprovalMode::Approval => AppointmentStatus::Pending,
            default => AppointmentStatus::Confirmed,
        };

        $service = $offer->serviceInterval($start);
        $blocked = $offer->blockedInterval($start);

        $appointment = new Appointment([
            'business_id' => $request->business->getKey(),
            'branch_id' => $branchId,
            'staff_id' => $offer->staff->getKey(),
            'service_id' => $offer->service->getKey(),
            'customer_user_id' => $request->customer?->getKey(),
            'booking_reference' => $this->references->generate(),
            'customer_name' => $request->customerName,
            'customer_email' => mb_strtolower($request->customerEmail),
            'customer_phone' => $request->customerPhone,
            'starts_at' => $service->start,
            'ends_at' => $service->end,
            'blocked_starts_at' => $blocked->start,
            'blocked_ends_at' => $blocked->end,
            'timezone' => $request->business->timezone,
            'approval_mode' => $settings->approval_mode,
            'payment_method' => $payOnline ? 'online' : 'pay_later',
            'currency' => $request->business->currency,
            'subtotal_minor' => $quote->subtotalMinor,
            'discount_minor' => $quote->discountMinor,
            'tax_minor' => $quote->taxMinor,
            'tax_rate_bps' => $settings->tax_rate_bps,
            'total_minor' => $quote->totalMinor,
            'deposit_minor' => $deposit,
            'customer_notes' => $request->customerNotes,
            'internal_notes' => $request->internalNotes,
            'source' => $request->source,
            'created_by_user_id' => $request->actor?->getKey(),
            'hold_expires_at' => $needsHold ? CarbonImmutable::now()->addMinutes(max(5, $settings->hold_minutes)) : null,
        ]);
        $appointment->status = $status;
        $appointment->payment_status = PaymentStatus::Unpaid;
        if ($status === AppointmentStatus::Confirmed) {
            $appointment->confirmed_at = CarbonImmutable::now();
        }
        $appointment->save();

        $appointment->items()->create([
            'service_id' => $offer->service->getKey(),
            'service_name' => $offer->service->name,
            'duration_minutes' => $offer->durationMinutes,
            'buffer_before_minutes' => $offer->service->buffer_before_minutes,
            'buffer_after_minutes' => $offer->service->buffer_after_minutes,
            'unit_price_minor' => $quote->unitPriceMinor,
            'quantity' => $quote->quantity,
            'line_total_minor' => $quote->subtotalMinor,
        ]);

        $this->stateMachine->record(
            $appointment,
            null,
            $status,
            $request->isStaffBooking() ? ActorType::Staff : ActorType::Customer,
            $request->actor ?? $request->customer,
            $request->isStaffBooking() ? 'Booked by staff' : 'Booked online',
        );

        return $appointment;
    }
}
