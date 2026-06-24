<?php

declare(strict_types=1);

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Verification\AnchorVerifier;

it('seeds a valid anchor that the AnchorVerifier accepts', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $checkpoint = Checkpoint::query()->findOrFail($ledger->lastCheckpointId);

    $this->seedAnchor($checkpoint->id);

    expect(app(AnchorVerifier::class)->checkpointHasValidAnchor($checkpoint))->toBeTrue();
});

it('seeds a tampered anchor that the AnchorVerifier rejects', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $checkpoint = Checkpoint::query()->findOrFail($ledger->lastCheckpointId);

    $this->seedAnchor($checkpoint->id, valid: false);

    expect(app(AnchorVerifier::class)->checkpointHasValidAnchor($checkpoint))->toBeFalse();
});
