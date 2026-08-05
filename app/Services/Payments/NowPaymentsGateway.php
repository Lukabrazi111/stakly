<?php

namespace App\Services\Payments;

use App\Services\Payments\Concerns\Unwired;

/**
 * NOWPayments custodial driver — stub. Real client (deposit accounts, mass
 * payouts, HMAC-SHA512 IPN verification over sorted JSON) lands in M9 once the
 * provider is confirmed and go-ahead is given. Config lives under
 * `services.payments.nowpayments`.
 */
final class NowPaymentsGateway implements PaymentGateway
{
    use Unwired;
}
