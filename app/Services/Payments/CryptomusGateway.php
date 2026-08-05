<?php

namespace App\Services\Payments;

use App\Services\Payments\Concerns\Unwired;

/**
 * Cryptomus custodial driver — stub. Real client (static wallets / invoices,
 * payouts, MD5(base64(body)+key) webhook verification) lands in M9 once the
 * provider is confirmed and go-ahead is given. Config lives under
 * `services.payments.cryptomus`. No true sandbox — testing uses Cryptomus's
 * free `/v1/test-webhook/payment` endpoint plus a cents-level live payment.
 */
final class CryptomusGateway implements PaymentGateway
{
    use Unwired;
}
