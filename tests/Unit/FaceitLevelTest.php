<?php

use App\Support\FaceitLevel;

it('returns null when the elo is null', function () {
    expect(FaceitLevel::fromElo(null))->toBeNull();
});

it('maps elo into the correct FACEIT level band', function () {
    expect(FaceitLevel::fromElo(100))->toBe(1)
        ->and(FaceitLevel::fromElo(500))->toBe(1)
        ->and(FaceitLevel::fromElo(501))->toBe(2)
        ->and(FaceitLevel::fromElo(900))->toBe(3)
        ->and(FaceitLevel::fromElo(1050))->toBe(4)
        ->and(FaceitLevel::fromElo(1200))->toBe(5)
        ->and(FaceitLevel::fromElo(1350))->toBe(6)
        ->and(FaceitLevel::fromElo(1530))->toBe(7)
        ->and(FaceitLevel::fromElo(1750))->toBe(8)
        ->and(FaceitLevel::fromElo(2000))->toBe(9)
        ->and(FaceitLevel::fromElo(2001))->toBe(10)
        ->and(FaceitLevel::fromElo(3500))->toBe(10);
});
