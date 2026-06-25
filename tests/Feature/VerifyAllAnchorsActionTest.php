<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\VerifyAnchorsJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('verifies all anchors synchronously and notifies success', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    Livewire::test(ListEntries::class)
        ->callAction('verifyAllAnchors')
        ->assertNotified('All anchors verified');
});

it('reports a failure when an in-scope anchor is invalid', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    // status 'anchored' with a tampered proof: NullAnchor::verify() fails.
    $this->seedAnchor($ledger->lastCheckpointId, valid: false);

    Livewire::test(ListEntries::class)
        ->callAction('verifyAllAnchors')
        ->assertNotified('Anchor verification failed');
});

it('queues the verify-all above the threshold instead of running it', function () {
    $this->enableAnchoring();
    Config::set('chronicle-filament.anchoring.verify_all_queue_threshold', 0);
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    Queue::fake();

    Livewire::test(ListEntries::class)
        ->callAction('verifyAllAnchors')
        ->assertNotified('Anchor verification queued');

    Queue::assertPushed(VerifyAnchorsJob::class);
});

it('hides the verify-all action when anchoring is disabled', function () {
    // No enableAnchoring(): core anchoring stays off.
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('verifyAllAnchors');
});

it('hides the verify-all action when authorization denies it', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2);
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('verifyAllAnchors');
});
