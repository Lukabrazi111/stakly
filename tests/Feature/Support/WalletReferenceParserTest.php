<?php

use App\Support\WalletReferenceParser;

/**
 * M31 Phase 2 — reference-ID prefix parser. Maps a `reference_id` string
 * onto either a contextual link (`parse`) or an underlying entity
 * (`parseEntity`) used by the sibling-row lookup. Lives under Feature/
 * because the URL form needs the framework's `route()` helper.
 */
test('returns null for invalid input shapes', function (?string $input) {
    expect(WalletReferenceParser::parse($input))->toBeNull();
    expect(WalletReferenceParser::parseEntity($input))->toBeNull();
})->with([
    'null' => [null],
    'empty' => [''],
    'no colon' => ['listing-create-42'],
    'unknown prefix' => ['fee:42'],
    'seed prefix' => ['seed:something'],
    'test prefix' => ['test:abc'],
    'non-numeric id' => ['listing-create:not-a-number'],
    'empty id' => ['match-payout:'],
]);

test('listing-bound prefixes parse to a listing URL', function (string $prefix) {
    $parsed = WalletReferenceParser::parse("{$prefix}:42");

    expect($parsed)->not->toBeNull();
    expect($parsed['label'])->toBe('Listing #42');
    expect($parsed['url'])->toBe(route('listings.show', 42));
})->with([
    'listing-create',
    'listing-cancel',
    'listing-expire',
    'match-take',
]);

test('match-bound prefixes parse to the admin disputes URL', function (string $prefix) {
    $parsed = WalletReferenceParser::parse("{$prefix}:7");

    expect($parsed)->not->toBeNull();
    expect($parsed['label'])->toBe('Match #7');
    expect($parsed['url'])->toBe(route('filament.admin.resources.disputes.view', 7));
})->with([
    'match-payout',
    'match-fee',
    'match-draw-creator',
    'match-draw-taker',
    'cancel-refund-creator',
    'cancel-refund-taker',
]);

test('parseEntity returns kind + id', function () {
    expect(WalletReferenceParser::parseEntity('listing-create:42'))
        ->toBe(['kind' => 'listing', 'id' => 42]);

    expect(WalletReferenceParser::parseEntity('match-payout:7'))
        ->toBe(['kind' => 'match', 'id' => 7]);
});

test('allReferencesFor enumerates every prefix targeting an entity', function () {
    $listingRefs = WalletReferenceParser::allReferencesFor('listing', 42);

    expect($listingRefs)->toContain('listing-create:42');
    expect($listingRefs)->toContain('listing-cancel:42');
    expect($listingRefs)->toContain('listing-expire:42');
    expect($listingRefs)->toContain('match-take:42');
    expect($listingRefs)->toHaveCount(4);

    $matchRefs = WalletReferenceParser::allReferencesFor('match', 7);

    expect($matchRefs)->toContain('match-payout:7');
    expect($matchRefs)->toContain('match-fee:7');
    expect($matchRefs)->toContain('match-draw-creator:7');
    expect($matchRefs)->toContain('match-draw-taker:7');
    expect($matchRefs)->toContain('match-draw:7');
    expect($matchRefs)->toContain('cancel-refund-creator:7');
    expect($matchRefs)->toContain('cancel-refund-taker:7');
    expect($matchRefs)->toContain('cancel-refund:7');
    expect($matchRefs)->toHaveCount(8);
});

/**
 * Team settlements stamp suffixed refs (`match-payout:42:player-7`,
 * `match-draw:42:9`, `cancel-refund:42:9`). The id is the FIRST numeric segment
 * after the prefix; the per-player discriminator must be ignored so the admin
 * money-trail link resolves to the match.
 */
test('suffixed team settlement refs resolve to the match', function (string $reference) {
    $parsed = WalletReferenceParser::parse($reference);

    expect($parsed)->not->toBeNull();
    expect($parsed['label'])->toBe('Match #42');
    expect($parsed['url'])->toBe(route('filament.admin.resources.disputes.view', 42));

    expect(WalletReferenceParser::parseEntity($reference))
        ->toBe(['kind' => 'match', 'id' => 42]);
})->with([
    'team payout' => ['match-payout:42:player-7'],
    'team draw' => ['match-draw:42:9'],
    'team cancel-refund' => ['cancel-refund:42:9'],
]);
