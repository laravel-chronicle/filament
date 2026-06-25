<?php

declare(strict_types=1);

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Filament\Support\KeyRingSnapshot;
use Chronicle\Filament\Support\SigningKeyState;
use Chronicle\Signing\Ed25519SigningProvider;
use Chronicle\Signing\KeyRing;

/**
 * Register a second, retired (verify-only) key alongside the active dev key so
 * the ring lists two keys: ed25519:chronicle-dev-key (active) + ed25519:retired-key.
 */
function withRetiredKey(): void
{
    config()->set('chronicle.signing.keys.retired-key', [
        'provider' => Ed25519SigningProvider::class,
        'algorithm' => 'ed25519',
        'public_key' => 'S3M/krzRO2474ArA6LY55R3ycmeF1v5FF3praMmmvdk=',
    ]);
    // Rebind the singleton so the new key is visible.
    app()->forgetInstance(KeyRing::class);
}

it('marks the active key active and others retired in the key list', function () {
    withRetiredKey();

    $keys = KeyRingSnapshot::make()->keys();

    expect($keys)->toHaveKey('ed25519:chronicle-dev-key')
        ->and($keys['ed25519:chronicle-dev-key']['active'])->toBeTrue()
        ->and($keys['ed25519:chronicle-dev-key']['algorithm'])->toBe('ed25519')
        ->and($keys['ed25519:chronicle-dev-key']['keyId'])->toBe('chronicle-dev-key')
        ->and($keys)->toHaveKey('ed25519:retired-key')
        ->and($keys['ed25519:retired-key']['active'])->toBeFalse();
});

it('reports the active key label', function () {
    expect(KeyRingSnapshot::make()->activeLabel())->toBe('ed25519:chronicle-dev-key');
});

it('derives active, retired, and unsigned state by (algorithm, key_id)', function () {
    $snapshot = KeyRingSnapshot::make();

    expect($snapshot->stateFor('ed25519', 'chronicle-dev-key'))->toBe(SigningKeyState::Active)
        ->and($snapshot->stateFor('ed25519', 'retired-key'))->toBe(SigningKeyState::Retired)
        ->and($snapshot->stateFor('ecdsa-p256', 'chronicle-dev-key'))->toBe(SigningKeyState::Retired)
        ->and($snapshot->stateFor(null, null))->toBe(SigningKeyState::Unsigned);
});

it('derives a checkpoint state from its stored key without querying', function () {
    $snapshot = KeyRingSnapshot::make();

    $active = new Checkpoint(['algorithm' => 'ed25519', 'key_id' => 'chronicle-dev-key']);
    $retired = new Checkpoint(['algorithm' => 'ed25519', 'key_id' => 'old-key']);

    expect($snapshot->forCheckpoint($active))->toBe(SigningKeyState::Active)
        ->and($snapshot->forCheckpoint($retired))->toBe(SigningKeyState::Retired);
});

it('derives an entry state from its checkpoint, Unsigned when none', function () {
    $snapshot = KeyRingSnapshot::make();

    $unlinked = new Entry;
    $unlinked->checkpoint_id = null;
    expect($snapshot->forEntry($unlinked))->toBe(SigningKeyState::Unsigned);

    $linked = new Entry;
    $linked->checkpoint_id = 'cp-1';
    $linked->setRelation('checkpoint', new Checkpoint(['algorithm' => 'ed25519', 'key_id' => 'chronicle-dev-key']));
    expect($snapshot->forEntry($linked))->toBe(SigningKeyState::Active);
});

it('counts checkpoints per key with a single aggregate', function () {
    $this->seedLedger(count: 6, checkpointEvery: 2); // 3 checkpoints, all under the active dev key

    $counts = KeyRingSnapshot::make()->checkpointCounts();

    expect($counts['ed25519:chronicle-dev-key'])->toBe(3);
});
