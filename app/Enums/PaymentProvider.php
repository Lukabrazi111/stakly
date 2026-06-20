<?php

namespace App\Enums;

/**
 * Custodial crypto payment providers behind the `PaymentGateway` contract.
 *
 * The active driver is config-driven (`services.payments.driver`); these cases
 * exist so provider-specific config paths, circuit-breaker keys, and webhook
 * routing have a typed identity even while the concrete clients are still
 * stubs (M9 — paused until go-ahead). `mock` is intentionally NOT a case here:
 * it's a driver name, not a real provider.
 */
enum PaymentProvider: string
{
    case NowPayments = 'nowpayments';
    case Cryptomus = 'cryptomus';

    public function displayName(): string
    {
        return match ($this) {
            self::NowPayments => 'NOWPayments',
            self::Cryptomus => 'Cryptomus',
        };
    }
}
