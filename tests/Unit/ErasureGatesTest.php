<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;

it('follows core encryption for crypto-shredding visibility by default', function () {
    // Core encryption off (default) -> visibility off.
    config()->set('chronicle.encryption.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isCryptoShreddingEnabled())->toBeFalse();

    // Core encryption on -> visibility follows it on.
    config()->set('chronicle.encryption.enabled', true);
    expect(ChronicleFilamentPlugin::make()->isCryptoShreddingEnabled())->toBeTrue();

    // Plugin config wins over core.
    config()->set('chronicle-filament.crypto_shredding.enabled', false);
    expect(ChronicleFilamentPlugin::make()->isCryptoShreddingEnabled())->toBeFalse()
        // Fluent override wins over everything.
        ->and(ChronicleFilamentPlugin::make()->cryptoShredding()->isCryptoShreddingEnabled())->toBeTrue();
});

it('keeps erasure off by default, overridable by config then fluent', function () {
    expect(ChronicleFilamentPlugin::make()->isErasureEnabled())->toBeFalse();

    config()->set('chronicle-filament.erasure.enabled', true);

    expect(ChronicleFilamentPlugin::make()->isErasureEnabled())->toBeTrue()
        ->and(ChronicleFilamentPlugin::make()->erasure(false)->isErasureEnabled())->toBeFalse();
});

it('keeps the hold override off by default, overridable by config then fluent', function () {
    expect(ChronicleFilamentPlugin::make()->isEraseHoldOverrideAllowed())->toBeFalse();

    config()->set('chronicle-filament.erasure.allow_hold_override', true);

    expect(ChronicleFilamentPlugin::make()->isEraseHoldOverrideAllowed())->toBeTrue()
        ->and(ChronicleFilamentPlugin::make()->eraseAllowHoldOverride(false)->isEraseHoldOverrideAllowed())->toBeFalse();
});

it('denies erase by default and only the eraseAuthorize closure grants it', function () {
    // No closure -> deny (unlike canVerify, which allows by default).
    expect(ChronicleFilamentPlugin::make()->canErase())->toBeFalse()
        ->and(ChronicleFilamentPlugin::make()->eraseAuthorize(fn () => true)->canErase())->toBeTrue()
        ->and(ChronicleFilamentPlugin::make()->eraseAuthorize(fn () => false)->canErase())->toBeFalse();
});

it('makes erasure unreachable without BOTH the flag and the authorize closure', function () {
    $reachable = fn (ChronicleFilamentPlugin $p): bool => $p->isErasureEnabled() && $p->canErase();

    // The plugin is a container singleton, so each scenario needs a fresh
    // instance - otherwise a fluent setter from one leaks into the next.
    $fresh = function (): ChronicleFilamentPlugin {
        app()->forgetInstance(ChronicleFilamentPlugin::class);

        return ChronicleFilamentPlugin::make();
    };

    // Flag on but no authorize -> unreachable.
    expect($reachable($fresh()->erasure()))->toBeFalse()
        // Authorize set but flag off -> unreachable.
        ->and($reachable($fresh()->eraseAuthorize(fn () => true)))->toBeFalse()
        // Both -> reachable.
        ->and($reachable($fresh()->erasure()->eraseAuthorize(fn () => true)))->toBeTrue();
});

it('does not let the verify gate grant erase', function () {
    // authorize() governs verify only; canErase stays denied.
    $plugin = ChronicleFilamentPlugin::make()->authorize(fn () => true);

    expect($plugin->canVerify())->toBeTrue()
        ->and($plugin->canErase())->toBeFalse();
});
