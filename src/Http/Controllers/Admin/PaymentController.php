<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Http\Controllers\Admin\Concerns\FindsAppointments;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\Payment;
use ME\Abs\Services\Payments\PaymentLedger;
use ME\Abs\Services\Payments\RefundService;
use ME\Abs\Support\Currency;

class PaymentController extends Controller
{
    use FindsAppointments;

    public function index(Request $request): View
    {
        $payments = Payment::query()
            ->where('business_id', $this->business()->getKey())
            ->whereIn('appointment_id', $this->visibleAppointments()->select('id'))
            ->when($request->string('status')->toString(), fn ($q, $status) => $q->where('status', $status))
            ->when($request->string('provider')->toString(), fn ($q, $provider) => $q->where('provider', $provider))
            ->with('appointment:id,booking_reference,customer_name', 'recordedBy:id,name')
            ->latest('id')
            ->paginate($this->pageSize())->withQueryString();

        return view('abs::admin.payments.index', ['payments' => $payments]);
    }

    public function store(Request $request, int $appointment, PaymentLedger $ledger): RedirectResponse
    {
        $model = $this->findAppointment($appointment);
        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', Rule::in(['cash', 'manual'])],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $ledger->recordManual($model, Currency::toMinor($data['amount'], $model->currency), $data['method'], $request->user(), $data['notes'] ?? null);
        } catch (InvalidArgumentException|PaymentException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return back()->with('success', 'Payment recorded.');
    }

    public function refund(Request $request, int $payment, RefundService $refunds): RedirectResponse
    {
        $model = Payment::query()
            ->where('business_id', $this->business()->getKey())
            ->whereIn('appointment_id', $this->visibleAppointments()->select('id'))
            ->findOrFail($payment);

        $data = $request->validate([
            'amount' => ['required', 'string', 'max:20'],
            'reason' => ['nullable', 'string', 'max:255'],
            'outside_system' => ['nullable', 'boolean'],
        ]);

        try {
            $refund = $refunds->request($model, Currency::toMinor($data['amount'], $model->currency), $data['reason'] ?? null, $request->user(), $request->boolean('outside_system'));
        } catch (InvalidArgumentException|PaymentException $e) {
            return back()->withInput()->withErrors(['refund_amount' => $e->getMessage()]);
        }

        return back()->with($refund->status->value === 'failed' ? 'error' : 'success', 'Refund '.$refund->status->value.'.');
    }
}
