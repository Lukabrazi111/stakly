<?php

namespace App\Support;

/**
 * Generates mock TRC20-style addresses. Visually identical to a real Tron
 * address (`T` + 33 base58 chars) but bears no cryptographic relationship to
 * any private key — nothing on-chain will accept funds at these addresses.
 * Replaced by real HD derivation when chain integration lands.
 */
final class MockTronAddress
{
    /**
     * Bitcoin base58 alphabet (omits visually-ambiguous 0, O, I, l). Real
     * Tron addresses use this alphabet so mocks should too.
     */
    private const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    private const ADDRESS_LENGTH = 34;

    public static function generate(): string
    {
        $alphabet = self::BASE58_ALPHABET;
        $alphabetLength = strlen($alphabet);

        // Real Tron addresses always begin with 'T' (version byte 0x41).
        $address = 'T';

        for ($i = 1; $i < self::ADDRESS_LENGTH; $i++) {
            $address .= $alphabet[random_int(0, $alphabetLength - 1)];
        }

        return $address;
    }
}
