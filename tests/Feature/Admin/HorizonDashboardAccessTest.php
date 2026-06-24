<?php

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * M38 P3 — the Horizon dashboard exposes job payloads, failed-job traces, and
 * retry/delete controls over the settlement + notification pipeline, so access
 * is locked to admins via the `viewHorizon` gate (= `User::isAdmin()`). In a
 * non-local environment Horizon enforces this gate and throws 403 otherwise;
 * the test env is `testing`, so the gate is live here.
 */

test('the viewHorizon gate allows admins and rejects everyone else', function () {
    $admin = User::factory()->admin()->create();
    $player = User::factory()->create();
    $platformAdmin = User::factory()->admin()->create(['is_platform' => true]);

    expect(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();
    expect(Gate::forUser($player)->allows('viewHorizon'))->toBeFalse();
    // Platform user is excluded even when admin-roled — defense in depth.
    expect(Gate::forUser($platformAdmin)->allows('viewHorizon'))->toBeFalse();
});

test('an admin can open the Horizon dashboard', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->get('/horizon')->assertOk();
});

test('a non-admin player is forbidden from the Horizon dashboard', function () {
    $player = User::factory()->create();

    $this->actingAs($player)->get('/horizon')->assertForbidden();
});

test('the platform user is forbidden from the Horizon dashboard', function () {
    $platformAdmin = User::factory()->admin()->create(['is_platform' => true]);

    $this->actingAs($platformAdmin)->get('/horizon')->assertForbidden();
});

test('a guest is forbidden from the Horizon dashboard (not locale-redirected)', function () {
    // A 403 here also proves `/horizon` is exempt from the locale-prefix
    // redirect — otherwise this would be a 301 to `/en/horizon`.
    $this->get('/horizon')->assertForbidden();
});
