<?php

namespace ME\Abs\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use ME\Abs\Exceptions\PaymentException;
use ME\Abs\Http\Controllers\Controller;
use ME\Abs\Models\PaymentGatewaySetting;
use ME\Abs\Payments\GatewayManager;
use Throwable;

/**
 * Per-business online payment gateways (bKash, Nagad, SSLCommerz, Stripe, PayPal).
 * Secret values are encrypted at rest and never sent back to the browser; leaving a secret field empty keeps it.
 */
class GatewayController extends Controller
{
    public function __construct(private GatewayManager $gateways) {}

    public function index(): View
    {
        $business = $this->business();
        $settings = PaymentGatewaySetting::query()->where('business_id', $business->getKey())->get()->keyBy('provider');

        return view('abs::admin.gateways.index', [
            'business' => $business,
            'providers' => $this->gateways->providers(),
            'settings' => $settings,
            'active' => $this->gateways->forBusiness($business)->keys()->all(),
            'canEdit' => $this->canInBusiness('abs_setting.edit'),
        ]);
    }

    public function update(Request $request, string $provider): RedirectResponse
    {
        $gateway = $this->gateways->providers()[$provider] ?? abort(404);
        $business = $this->business();
        $fields = $gateway::fields();

        $rules = ['is_enabled' => ['nullable', 'boolean'], 'is_sandbox' => ['nullable', 'boolean']];
        foreach ($fields as $key => $field) {
            $rules["credentials.{$key}"] = ['nullable', 'string', Rule::when(($field['type'] ?? 'text') === 'textarea', ['max:8000'], ['max:500'])];
        }
        $data = $request->validate($rules);

        $row = PaymentGatewaySetting::query()->firstOrNew(['business_id' => $business->getKey(), 'provider' => $provider]);
        $credentials = (array) ($row->credentials ?? []);
        foreach ($fields as $key => $field) {
            $value = trim((string) ($data['credentials'][$key] ?? ''));
            // Secret fields are write-only: empty means "keep the saved value".
            if ($value !== '' || ! ($field['secret'] ?? false)) {
                $credentials[$key] = $value !== '' ? $value : null;
            }
        }

        $before = $row->exists ? ['enabled' => $row->is_enabled, 'sandbox' => $row->is_sandbox] : [];
        $row->fill([
            'is_enabled' => $request->boolean('is_enabled'),
            'is_sandbox' => $request->boolean('is_sandbox'),
            'credentials' => $credentials,
        ])->save();

        $configured = $this->gateways->fromSetting($row, $business) !== null;
        me_change_log('Payment gateway '.$provider.' updated', 'abs.gateway.update')
            ->record($before, ['enabled' => $row->is_enabled, 'sandbox' => $row->is_sandbox, 'credentials' => 'changed (hidden)']);

        if ($row->is_enabled && ! $configured) {
            return back()->with('error', $gateway->label().' is enabled but some required fields are empty, so customers will not see it yet.');
        }

        return back()->with('success', $gateway->label().' saved.');
    }

    public function test(string $provider): RedirectResponse
    {
        $this->gateways->providers()[$provider] ?? abort(404);
        $row = PaymentGatewaySetting::query()->where('business_id', $this->business()->getKey())->where('provider', $provider)->first();
        $gateway = $row ? $this->gateways->fromSetting($row, $this->business()) : null;

        if (! $gateway) {
            return back()->with('error', 'Save all required fields first.');
        }

        try {
            return back()->with('success', $gateway->test());
        } catch (PaymentException $e) {
            return back()->with('error', $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Could not reach the provider: '.mb_substr($e->getMessage(), 0, 160));
        }
    }
}
