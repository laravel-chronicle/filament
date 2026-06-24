<?php

declare(strict_types=1);

use Chronicle\Filament\Support\AnchorState;
use Chronicle\Filament\Support\VerificationResultStore;

it('records a valid anchor verification as Anchored', function () {
    $store = app(VerificationResultStore::class);

    $store->recordAnchor('cp-1', valid: true);

    expect($store->anchorState('cp-1'))->toBe(AnchorState::Anchored);
});

it('records an invalid anchor verification as Invalid with the AnchorInvalid code', function () {
    $store = app(VerificationResultStore::class);

    $record = $store->recordAnchor('cp-1', valid: false);

    expect($store->anchorState('cp-1'))->toBe(AnchorState::Invalid)
        ->and($record->failure_code)->toBe('anchor_invalid');
});

it('returns Unanchored for a checkpoint that was never anchor-verified', function () {
    expect(app(VerificationResultStore::class)->anchorState('cp-unknown'))->toBe(AnchorState::Unanchored);
});
