<?php

declare(strict_types=1);

use Chronicle\Anchoring\CheckpointAnchor;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Filament\Support\AnchorState;

it('maps each state to a distinct badge color', function () {
    expect(AnchorState::Anchored->color())->toBe('success')
        ->and(AnchorState::Pending->color())->toBe('warning')
        ->and(AnchorState::Failed->color())->toBe('danger')
        ->and(AnchorState::Invalid->color())->toBe('danger')
        ->and(AnchorState::Unanchored->color())->toBe('gray');
});

it('exposes a human label and an icon per state', function () {
    expect(AnchorState::Anchored->label())->toBe('Anchored')
        ->and(AnchorState::Pending->label())->toBe('Pending')
        ->and(AnchorState::Failed->label())->toBe('Failed')
        ->and(AnchorState::Invalid->label())->toBe('Invalid')
        ->and(AnchorState::Unanchored->label())->toBe('Unanchored')
        ->and(AnchorState::Anchored->icon())->toBe('heroicon-o-link')
        ->and(AnchorState::Invalid->icon())->toBe('heroicon-o-shield-exclamation');
});

it('derives state from stored anchor statuses', function () {
    expect(AnchorState::fromStatuses([]))->toBe(AnchorState::Unanchored)
        ->and(AnchorState::fromStatuses(['anchored']))->toBe(AnchorState::Anchored)
        ->and(AnchorState::fromStatuses(['pending']))->toBe(AnchorState::Pending)
        ->and(AnchorState::fromStatuses(['failed']))->toBe(AnchorState::Failed)
        // anchored wins over any other status (multi-provider checkpoint)
        ->and(AnchorState::fromStatuses(['failed', 'anchored', 'pending']))->toBe(AnchorState::Anchored)
        // failed surfaces over pending when nothing is anchored
        ->and(AnchorState::fromStatuses(['pending', 'failed']))->toBe(AnchorState::Failed)
        // unknown / disabled-with-no-rows degrades to Unanchored, never an error
        ->and(AnchorState::fromStatuses(['bogus']))->toBe(AnchorState::Unanchored);
});

it('derives a checkpoint state from its anchor rows without querying', function () {
    $checkpoint = new Checkpoint;
    $checkpoint->setRelation('anchors', collect([
        new CheckpointAnchor(['status' => 'failed']),
        new CheckpointAnchor(['status' => 'anchored']),
    ]));

    expect(AnchorState::forCheckpoint($checkpoint))->toBe(AnchorState::Anchored);

    $bare = new Checkpoint;
    $bare->setRelation('anchors', collect());

    expect(AnchorState::forCheckpoint($bare))->toBe(AnchorState::Unanchored);
});

it('derives an entry state from its checkpoint, Unanchored when none', function () {
    $unlinked = new Entry;
    $unlinked->checkpoint_id = null;

    expect(AnchorState::forEntry($unlinked))->toBe(AnchorState::Unanchored);

    $checkpoint = new Checkpoint;
    $checkpoint->setRelation('anchors', collect([
        new CheckpointAnchor(['status' => 'anchored']),
    ]));

    $linked = new Entry;
    $linked->checkpoint_id = 'cp-1';
    $linked->setRelation('checkpoint', $checkpoint);

    expect(AnchorState::forEntry($linked))->toBe(AnchorState::Anchored);
});
