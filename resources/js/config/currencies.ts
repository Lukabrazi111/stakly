// Frontend currency registry. v1 = USDT only (per MVP scope lock).
// Non-USDT entries are shown with "Soon" badges — same pattern as the
// homepage GameSelector — so the UI doesn't look empty and the future
// surface area is signposted.
//
// Adding a real currency:
//   1) Flip `available: true` here.
//   2) Wire it on the backend (new chain integration, ledger work, etc.)
//   3) Update any controllers that hardcode USDT.

export type CurrencyId = 'USDT' | 'BTC' | 'ETH';

export interface CurrencyConfig {
    id: CurrencyId;
    symbol: string; // short display symbol (₮ / ₿ / Ξ)
    available: boolean;
}

export const CURRENCIES: readonly CurrencyConfig[] = [
    { id: 'USDT', symbol: '₮', available: true },
    { id: 'BTC', symbol: '₿', available: false },
    { id: 'ETH', symbol: 'Ξ', available: false },
] as const;

export const DEFAULT_CURRENCY: CurrencyId = 'USDT';
