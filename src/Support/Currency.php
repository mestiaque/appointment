<?php

namespace ME\Abs\Support;

use InvalidArgumentException;

/**
 * Money helpers. Amounts are always integers in the currency's minor unit (cents, paisa, yen ...).
 * Floats are never used for money.
 */
final class Currency
{
    /** @var array<string, int> ISO 4217 currencies whose minor unit is not 2 decimals */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'ISK' => 0, 'JPY' => 0, 'KMF' => 0, 'KRW' => 0,
        'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'UYI' => 0, 'VND' => 0, 'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    public static function exponent(string $currency): int
    {
        return self::EXPONENTS[strtoupper($currency)] ?? 2;
    }

    /**
     * Convert a decimal string typed by a user ("12.5", "1,200.00") to minor units, without floats.
     */
    public static function toMinor(string|int $amount, string $currency): int
    {
        $amount = str_replace([',', ' '], '', trim((string) $amount));
        $exponent = self::exponent($currency);

        if (! preg_match('/^\d+(?:\.(\d+))?$/', $amount, $matches)) {
            throw new InvalidArgumentException('Invalid amount.');
        }

        $fraction = $matches[1] ?? '';
        if (strlen($fraction) > $exponent && rtrim(substr($fraction, $exponent), '0') !== '') {
            throw new InvalidArgumentException("Amount has more than {$exponent} decimal places.");
        }

        [$whole] = explode('.', $amount);
        $fraction = str_pad(substr($fraction, 0, $exponent), $exponent, '0');

        return (int) ltrim($whole.$fraction, '0') ?: 0;
    }

    /**
     * Minor units to a plain decimal string ("1250" USD -> "12.50"), suitable for form inputs.
     */
    public static function toDecimal(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);

        if ($exponent === 0) {
            return (string) $minor;
        }

        $padded = str_pad((string) abs($minor), $exponent + 1, '0', STR_PAD_LEFT);

        return ($minor < 0 ? '-' : '').substr($padded, 0, -$exponent).'.'.substr($padded, -$exponent);
    }

    /**
     * Display format: "USD 1,250.00".
     */
    public static function format(int $minor, string $currency): string
    {
        $exponent = self::exponent($currency);
        $decimal = self::toDecimal($minor, $currency);
        [$whole, $fraction] = array_pad(explode('.', ltrim($decimal, '-')), 2, '');
        $whole = number_format((int) $whole, 0, '.', ',');

        return strtoupper($currency).' '.($minor < 0 ? '-' : '').$whole.($exponent > 0 ? '.'.$fraction : '');
    }

    /**
     * Basis-point percentage of an amount, rounded half up (1 bps = 0.01 %).
     */
    public static function percentOf(int $minor, int $basisPoints): int
    {
        return intdiv($minor * $basisPoints + 5000, 10000);
    }

    /**
     * @return array<int, string>
     */
    public static function commonCodes(): array
    {
        return ['USD', 'EUR', 'GBP', 'BDT', 'INR', 'PKR', 'AED', 'SAR', 'MYR', 'SGD', 'AUD', 'CAD', 'JPY', 'KWD', 'BHD', 'OMR'];
    }
}
