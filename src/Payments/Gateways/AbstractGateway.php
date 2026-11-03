<?php

namespace ME\Abs\Payments\Gateways;

use ME\Abs\Models\Business;
use ME\Abs\Payments\PaymentGateway;
use ME\Abs\Support\Currency;

/**
 * Shared credential handling. Credentials come from the admin panel (per business, encrypted);
 * when a gateway is used without them, legacy .env config (legacyConfig()) is used instead.
 */
abstract class AbstractGateway implements PaymentGateway
{
    /** @var array<string, string|null> */
    protected array $credentials = [];

    protected bool $sandbox = true;

    protected bool $fromDatabase = false;

    protected ?Business $business = null;

    public function withCredentials(array $credentials, bool $sandbox, ?Business $business = null): static
    {
        $copy = clone $this;
        $copy->credentials = $credentials;
        $copy->sandbox = $sandbox;
        $copy->business = $business;
        $copy->fromDatabase = true;

        return $copy;
    }

    public function isConfigured(): bool
    {
        foreach (static::fields() as $key => $field) {
            if (($field['required'] ?? true) && blank($this->cred($key))) {
                return false;
            }
        }

        return true;
    }

    public function currencies(): ?array
    {
        return null;
    }

    public function capturesOnReturn(): bool
    {
        return true;
    }

    public function isSandbox(): bool
    {
        return $this->sandbox;
    }

    /**
     * Field key => config key used when no admin-panel credentials exist (backwards compatible .env setup).
     *
     * @return array<string, string>
     */
    protected function legacyConfig(): array
    {
        return [];
    }

    protected function cred(string $key): ?string
    {
        if ($this->fromDatabase) {
            $value = $this->credentials[$key] ?? null;

            return filled($value) ? trim((string) $value) : null;
        }

        $configKey = $this->legacyConfig()[$key] ?? null;

        return $configKey && filled(config($configKey)) ? (string) config($configKey) : null;
    }

    protected function amount(int $minor, string $currency): string
    {
        return Currency::toDecimal($minor, $currency);
    }
}
