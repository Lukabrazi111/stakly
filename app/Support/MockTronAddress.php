<?php

namespace App\Support;

/**
 * Generates mock TRC20-style addresses for v1. Visually identical to a real
 * Tron address (`T` + 33 base58 characters, total 34 chars) but bears no
 * cryptographic relationship to any private key — nothing on-chain will
 * accept funds at these addresses.
 *
 * Real HD derivation from the platform master seed (BIP32/39/44) replaces
 * this helper in the pre-launch chain integration gate. See milestones.md
 * "Chain custody architecture".
 */
final class MockTronAddress
{
    /**
     * Tron uses Bitcoin's base58 alphabet — 58 characters, omitting `0`, `O`,
     * `I`, and `l` to avoid visual confusion. Real addresses use this alphabet
     * so mocks should too.
     */
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const ADDRESS_LENGTH = 34;

    public static function generate(): string
    {
        $alphabet = self::BASE58_ALPHABET;
        $alphabetLength = strlen($alphabet);

        // Real Tron addresses always begin with 'T' (the base58-encoded form
        // of the address version byte 0x41).
        $address = 'T';

        for ($i = 1; $i < self::ADDRESS_LENGTH; $i++) {
            $address .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $address;
    }
}
