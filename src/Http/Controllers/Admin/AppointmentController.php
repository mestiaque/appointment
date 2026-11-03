<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use ME\Abs\Actions\AppointmentDiscountAction;
use ME\Abs\Actions\AppointmentStateMachine;
use ME\Abs\Enums\ActorType;
use ME\Abs\Enums\AppointmentStatus;
use ME\Abs\Exceptions\BookingException;
use ME\Abs\Http\Controllers\Admin\Concerns\FindsAppointments;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Services\Payments\InvoiceService;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Support\Currency;

class AppointmentController extends Controller
{
    use FindsAppointments;

    public function index(): View
    {
        return view('abs::admin.appointments.index');
    }

    public function create(): View
    {
        return view('abs::admin.appointments.create');
    }

    public function show(int $appointment, AppointmentStateMachine $stateMachine): View
    {
        $model = $this->findAppointment($appointment)->load([
            'items', 'staff', 'branch', 'service', 'customer:id,name,email',
            'statusHistories.actor:id,name', 'rescheduleHistories.actor:id,name',
            'payments.refunds', 'payments.recordedBy:id,name', 'invoice',
        ]);

        $history = $this->visibleAppointments()
            ->where('customer_email', $model->customer_email)
            ->whereKeyNot($model->getKey())
            ->with('items:id,appointment_id,service_name')
            ->latest('starts_at')
            ->limit(10)
            ->get(['id', 'booking_reference', 'starts_at', 'timezone', 'status']);

        return view('abs::admin.appointments.show', [
            'appointment' => $model,
            'history' => $history,
            'transitions' => array_values(array_filter(
                $this->availableTransitions($model->status),
                fn (AppointmentStatus $s) => $stateMachine->tooEarly($model, $s) === null,
            )),
            'can' => [
                'approve' => $this->canInBusiness('abs_appointment.approve'),
                'cancel' => $this->canInBusiness('abs_appointment.cancel'),
                'status' => $this->canInBusiness('abs_appointment.status'),
                'reschedule' => $this->canInBusiness('abs_appointment.reschedule'),
                'edit' => $this->canInBusiness('abs_appointment.edit'),
                'record' => $this->canInBusiness('abs_payment.record'),
                'refund' => $this->canInBusiness('abs_payment.refund'),
                'invoice' => $this->canInBusiness('abs_payment.view'),
            ],
        ]);
    }

    public function transition(Request $request, int $appointment, AppointmentStateMachine $stateMachine, RefundService $refunds): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate([
            'status' => ['required', Rule::in(['confirmed', 'rejected', 'cancelled', 'checked_in', 'in_progress', 'completed', 'no_show'])],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        $to = AppointmentStatus::from($data['status']);

        $this->authorizeInBusiness(match (true) {
            in_array($to, [AppointmentStatus::Confirmed, AppointmentStatus::Rejected], true) && $model->status === AppointmentStatus::Pending => 'abs_appointment.approve',
            $to === AppointmentStatus::Cancelled => 'abs_appointment.cancel',
            default => 'abs_appointment.status',
        });

        try {
            $stateMachine->transition($model, $to, ActorType::Staff, $request->user(), $data['reason'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Policy: a rejected booking that was already paid online is refunded in full automatically.
        if ($to === AppointmentStatus::Rejected) {
            foreach ($model->payments()->get() as $payment) {
                if ($payment->refundableMinor() > 0 && ! in_array($payment->provider, ['cash', 'manual'], true)) {
                    $refunds->request($payment, $payment->refundableMinor(), 'Booking rejected', $request->user());
                }
            }
        }

        return back()->with('success', 'Status changed to '.$to->label().'.');
    }

    public function discount(Request $request, int $appointment, AppointmentDiscountAction $action): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate(['discount' => ['required', 'string', 'max:20']]);

        try {
            $action->execute($model, Currency::toMinor($data['discount'], $model->currency), $request->user());
        } catch (InvalidArgumentException|BookingException $e) {
            return back()->withInput()->withErrors(['discount' => $e->getMessage()]);
        }

        return back()->with('success', 'Price updated.');
    }

    /**
     * Prescription / report shared with the customer (visible on their booking page after phone verification).
     */
    public function uploadDocument(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate([
            'document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
            'title' => ['required', 'string', 'max:120'],
        ]);

        $media = $model->addMedia($request->file('document'), 'documents', ['title' => $data['title']]);
        me_change_log('Document added to booking '.$model->booking_reference, 'abs.appointment.document')
            ->subject($model)->record([], ['document' => $media->title, 'file' => $media->original_name]);

        return back()->with('success', 'Document uploaded.');
    }

    public function deleteDocument(int $appointment, int $document): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $media = $model->getMedia('documents')->firstWhere('id', $document);
        abort_unless($media, 404);

        $model->deleteMedia([$media->id], 'documents');
        me_change_log('Document removed from booking '.$model->booking_reference, 'abs.appointment.document')
            ->subject($model)->record(['document' => $media->title], []);

        return back()->with('success', 'Document removed.');
    }

    public function updateNotes(Request $request, int $appointment): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate(['internal_notes' => ['nullable', 'string', 'max:5000']]);
        $model->update($data);

        return back()->with('success', __('abs::abs.saved'));
    }

    public function invoice(int $appointment, InvoiceService $invoices): View
    {
        $model = $this->findAppointment($appointment)->load('items', 'business', 'payments', 'staff');

        return view('abs::admin.appointments.invoice', [
            'appointment' => $model,
            'invoice' => $invoices->issue($model),
        ]);
    }

    /**
     * @return array<int, AppointmentStatus>
     */
    private function availableTransitions(AppointmentStatus $status): array
    {
        return array_values(array_filter($status->allowedTransitions(), fn ($s) => $s !== AppointmentStatus::Expired && $s !== AppointmentStatus::Pending));
    }
}
