<?php

declare(strict_types=1);

use Chronicle\Anchoring\AnchorManager;
use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Widgets\AnchorCoverageWidget;
use Chronicle\Verification\AnchorVerifier;
use Livewire\Livewire;

it('summarises anchor coverage from cheap table aggregates', function () {
    // Force the surface on at the plugin level with core anchoring off, so seeding
    // does not auto-anchor every checkpoint; then anchor one and leave the other
    // pending, so the coverage aggregate is a deterministic 1 / 2.
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 4, checkpointEvery: 2); // 2 checkpoints

    $ids = Checkpoint::query()->pluck('id')->all();
    $this->seedAnchor($ids[0], status: 'anchored');
    $this->seedAnchor($ids[1], status: 'pending');

    Livewire::test(AnchorCoverageWidget::class)
        ->assertOk()
        ->assertSee('Anchor coverage')
        ->assertSee('1 / 2')
        ->assertSee('Pending');
});

it('does not run a provider anchor verification on widget load', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId, status: 'anchored');

    // AnchorVerifier is a readonly (non-final) class; bind a subclass that blows
    // up if the widget ever resolves and runs a provider verification on render.
    app()->bind(AnchorVerifier::class, fn (): AnchorVerifier => new readonly class(app(AnchorManager::class)) extends AnchorVerifier
    {
        public function checkpointHasValidAnchor(Checkpoint $checkpoint): bool
        {
            throw new RuntimeException('AnchorVerifier must not run during widget render');
        }
    });

    Livewire::test(AnchorCoverageWidget::class)->assertOk();
});

it('is hidden when anchoring is disabled', function () {
    // No enableAnchoring(): core anchoring stays off.
    $this->seedLedger(count: 2, checkpointEvery: 2);

    expect(AnchorCoverageWidget::canView())->toBeFalse();
});
