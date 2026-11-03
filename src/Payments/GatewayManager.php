<?php

namespace ME\Abs\Payments;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use ME\Abs\Models\Business;
use ME\Abs\Models\PaymentGatewaySetting;
use ME\Abs\Payments\Gateways\BkashGateway;
use ME\Abs\Payments\Gateways\NagadGateway;
use ME\Abs\Payments\Gateways\PayPalGateway;
use ME\Abs\Payments\Gateways\SslCommerzGateway;
use ME\Abs\Payments\Gateways\StripeGateway;

/**
 * Resolves payment gateways. Credentials normally come from the admin "Payment Gateways" page (per business);
 * a gateway configured only in .env (ABS_PAYMENT_GATEWAY + keys) still works as a fallback.
 */
class GatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private array $drivers = [
        'bkash' => BkashGateway::class,
        'nagad' => NagadGateway::class,
        'sslcommerz' => SslCommerzGateway::class,
        'stripe' => StripeGateway::class,
        'paypal' => PayPalGateway::class,
    ];

    public function __construct(private Container $container) {}

    /**
     * Enabled, fully configured gateways that accept the business's currency, keyed by provider name.
     *
     * @return Collection<string, PaymentGateway>
     */
    public function forBusiness(Business $business): Collection
    {
        $settings = PaymentGatewaySetting::query()->where('business_id', $business->getKey())->orderBy('sort_order')->orderBy('id')->get()->keyBy('provider');
        $gateways = collect();

        foreach ($settings as $provider => $row) {
            if ($row->is_enabled && ($gateway = $this->fromSetting($row, $business))) {
                $gateways[$provider] = $gateway;
            }
        }

        $legacy = config('abs.gateway');
        if ($legacy && ! $settings->has($legacy) && ($gateway = $this->make($legacy)) && $gateway->isConfigured()) {
            $gateways[$legacy] = $gateway;
        }

        return $gateways->filter(fn (PaymentGateway $g) => $g->currencies() === null || in_array(strtoupper($business->currency), $g->currencies(), true));
    }

    public function available(Business $business): bool
    {
        return $this->forBusiness($business)->isNotEmpty();
    }

    /**
     * A gateway by name, with the business's saved credentials (even when disabled, so refunds and webhooks
     * for earlier payments still work). Falls back to .env configuration.
     */
    public function driver(string $name, ?Business $business = null): ?PaymentGateway
    {
        if (! isset($this->drivers[$name])) {
            return null;
        }

        if ($business && ($row = PaymentGatewaySetting::query()->where('business_id', $business->getKey())->where('provider', $name)->first())) {
            return $this->fromSetting($row, $business);
        }

        $gateway = $this->make($name);

        return $gateway->isConfigured() ? $gateway : null;
    }

    /**
     * Unconfigured instance of every supported provider (for the admin page).
     *
     * @return array<string, PaymentGateway>
     */
    public function providers(): array
    {
        return collect($this->drivers)->mapWithKeys(fn ($class, $name) => [$name => $this->make($name)])->all();
    }

    public function fromSetting(PaymentGatewaySetting $row, Business $business): ?PaymentGateway
    {
        $gateway = $this->make($row->provider)?->withCredentials((array) ($row->credentials ?? []), $row->is_sandbox, $business);

        return $gateway?->isConfigured() ? $gateway : null;
    }

    /**
     * @param  class-string<PaymentGateway>  $class
     */
    public function extend(string $name, string $class): void
    {
        $this->drivers[$name] = $class;
    }

    private function make(string $name): ?PaymentGateway
    {
        $class = $this->drivers[$name] ?? null;

        return $class ? $this->container->make($class) : null;
    }
}
