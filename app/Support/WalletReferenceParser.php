<?php

namespace App\Support;

/**
 * Maps a `wallet_transactions.reference_id` to a human label + URL into the
 * related entity. Used by the M31 wallet-ledger View page so an admin can
 * jump from a fee row straight to the match it came from.
 *
 * The prefix → entity mapping mirrors the exact strings the `App\Services\Wallet`
 * callsites stamp (grepped from `app/`, seeders, and tests). Unrecognised /
 * internal / seed / test prefixes return null — the row still renders, just
 * without a contextual link.
 *
 * Today only `listings` (public) and `disputes` (admin) destinations are
 * wired, because those are the Filament resources that exist. When M32
 * lands a real admin `ListingResource`, the listing-bound prefixes can be
 * pointed there instead of the public page; the parser is the only file
 * that changes.
 */
class WalletReferenceParser
{
    /**
     * `{prefix} => {kind}` map. `kind` is `'listing'` (route to public
     * listing page) or `'match'` (route to admin match view).
     *
     * @var array<string, string>
     */
    private const PREFIXES = [
        'listing-create' => 'listing',
        'listing-cancel' => 'listing',
        'listing-expire' => 'listing',
        'match-take' => 'listing',
        'match-payout' => 'match',
        'match-fee' => 'match',
        'match-draw-creator' => 'match',
        'match-draw-taker' => 'match',
        'match-draw' => 'match',
        'cancel-refund-creator' => 'match',
        'cancel-refund-taker' => 'match',
        'cancel-refund' => 'match',
    ];

    /**
     * @return array{label: string, url: string}|null
     */
    public static function parse(?string $reference): ?array
    {
        $entity = self::parseEntity($reference);

        if ($entity === null) {
            return null;
        }

        return match ($entity['kind']) {
            'listing' => [
                'label' => "Listing #{$entity['id']}",
                'url' => route('listings.show', $entity['id']),
            ],
            'match' => [
                'label' => "Match #{$entity['id']}",
                'url' => route('filament.admin.resources.disputes.view', $entity['id']),
            ],
        };
    }

    /**
     * Extracts the underlying entity (kind + numeric id) from a reference_id
     * without generating URLs. Used by the sibling-transaction lookup so the
     * infolist can collect every row pointing at the same listing / match
     * regardless of which prefix produced it.
     *
     * Team settlements stamp suffixed refs (`match-payout:42:player-7`,
     * `match-draw:42:9`, `cancel-refund:42:9`), so the id is the FIRST numeric
     * segment after the prefix — anything past it is a per-player discriminator.
     *
     * @return array{kind: string, id: int}|null
     */
    public static function parseEntity(?string $reference): ?array
    {
        if ($reference === null || ! str_contains($reference, ':')) {
            return null;
        }

        [$prefix, $rest] = explode(':', $reference, 2);
        $kind = self::PREFIXES[$prefix] ?? null;

        if ($kind === null) {
            return null;
        }

        $id = strtok($rest, ':');

        if ($id === false || ! ctype_digit($id)) {
            return null;
        }

        return ['kind' => $kind, 'id' => (int) $id];
    }

    /**
     * Every known reference_id that could point at a given entity. Used to
     * build the sibling-row query — one indexed `whereIn` instead of LIKE.
     *
     * @return list<string>
     */
    public static function allReferencesFor(string $kind, int $id): array
    {
        return collect(self::PREFIXES)
            ->filter(fn (string $entityKind) => $entityKind === $kind)
            ->keys()
            ->map(fn (string $prefix) => "{$prefix}:{$id}")
            ->values()
            ->all();
    }
}
