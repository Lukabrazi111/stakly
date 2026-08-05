<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Identity-verification state of a user (M9 Phase 0c).
 *
 * Only meaningful when `stakly.kyc_enabled` is on — which it is NOT by default.
 * NOWPayments does not require KYC for crypto-only merchants, nor on our end
 * users in the permanent-address deposit model, so nothing forces this today.
 * The column exists so turning verification on later is a config flip plus an
 * admin action, rather than a schema migration on a live money table.
 *
 * Transitions are admin-driven: there is deliberately no document-upload flow,
 * because picking a KYC vendor is a decision we haven't made. An operator
 * verifies out-of-band and records the outcome here.
 */
enum KycStatus: string implements HasColor, HasLabel
{
    /** Default. Never asked, or asked and not yet submitted. */
    case Unverified = 'unverified';

    /** Documents received; an operator is reviewing them. */
    case Pending = 'pending';

    /** Verified. Withdrawals are ungated regardless of volume. */
    case Verified = 'verified';

    /** Verification refused. Treated exactly like `Unverified` by the gate. */
    case Rejected = 'rejected';

    public function getLabel(): string
    {
        return match ($this) {
            self::Unverified => 'Unverified',
            self::Pending => 'Pending review',
            self::Verified => 'Verified',
            self::Rejected => 'Rejected',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Unverified => 'gray',
            self::Pending => 'warning',
            self::Verified => 'success',
            self::Rejected => 'danger',
        };
    }

    /**
     * Whether this state satisfies the withdrawal gate.
     *
     * `Pending` deliberately does NOT pass: a player must not be able to
     * unblock a large cash-out merely by submitting documents that nobody has
     * looked at yet.
     */
    public function satisfiesGate(): bool
    {
        return $this === self::Verified;
    }
}
