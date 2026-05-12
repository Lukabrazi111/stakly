<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown by App\Services\Wallet when a debit operation (Withdrawal or
 * EscrowHold) would drive a user's `usdt_balance` negative.
 *
 * Idempotent replay (calling the same method twice with the same
 * `reference_id`) is NOT an exception — it returns the existing transaction.
 */
class InsufficientBalanceException extends Exception {}
