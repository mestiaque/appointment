<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ME\Abs\Enums\ApprovalMode;
use ME\Abs\Enums\PaymentMode;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Payments\GatewayManager;
use ME\Abs\Support\Currency;

class SettingController extends Controller
{
    public function edit(GatewayManager $gateways): View
    {
        return view('abs::admin.settings.edit', [
            'business' => $this->business(),
            'settings' => $this->business()->settings(),
            'onlineAvailable' => $gateways->available($this->business()),
            'canEdit' => $this->canInBusiness('abs_setting.edit'),
        ]);
    }

    public function update(Request $request, GatewayManager $gateways): RedirectResponse
    {
        $data = $request->validate([
            'approval_mode' => ['required', Rule::enum(ApprovalMode::class)],
            'payment_mode' => ['required', Rule::enum(PaymentMode::class)],
            'min_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'max_days_ahead' => ['required', 'integer', 'min:1', 'max:730'],
            'slot_interval_minutes' => ['required', 'integer', Rule::in([5, 10, 15, 20, 30, 45, 60, 90, 120])],
            'cancellation_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'reschedule_notice_minutes' => ['required', 'integer', 'min:0', 'max:525600'],
            'hold_minutes' => ['required', 'integer', 'min:30', 'max:1440'],
            'tax_rate_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'reminder_minutes_before' => ['required', 'integer', 'min:0', 'max:20160'],
        ]);

        if ($data['payment_mode'] !== PaymentMode::PayLater->value && ! $gateways->available($this->business())) {
            return back()->withInput()->withErrors(['payment_mode' => 'Online payment needs at least one enabled gateway (Booking → Payment Gateways).']);
        }

        $settings = $this->business()->settings();
        $values = collect($data)->except('tax_rate_percent')->all() + [
            // "12.5" % -> 1250 basis points, parsed as a string (no float rounding).
            'tax_rate_bps' => Currency::toMinor((string) $data['tax_rate_percent'], 'USD'),
        ];
        foreach (['pending_blocks_slot', 'allow_guest_booking', 'require_customer_phone', 'allow_customer_cancellation', 'allow_customer_reschedule'] as $flag) {
            $values[$flag] = $request->boolean($flag);
        }

        me_change_log('Booking settings updated: '.$this->business()->name, 'abs.settings.update')
            ->watch($settings)
            ->run(fn () => $settings->update($values));

        return back()->with('success', __('abs::abs.saved'));
    }
}
