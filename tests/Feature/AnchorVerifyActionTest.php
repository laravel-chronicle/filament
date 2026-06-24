<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Filament\Support\AnchorState;
use Chronicle\Filament\Support\VerificationResultStore;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('verifies a valid anchor and records it as Anchored', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry));

    expect(app(VerificationResultStore::class)->anchorState($ledger->lastCheckpointId))->toBe(AnchorState::Anchored);
});

it('records a tampered anchor as Invalid', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId, valid: false);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyAnchor')->table($entry));

    expect(app(VerificationResultStore::class)->anchorState($ledger->lastCheckpointId))->toBe(AnchorState::Invalid);
});

it('hides the verify-anchor action when the entry is unanchored', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 1); // checkpoint_id null

    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('hides the verify-anchor action when anchoring is disabled', function () {
    // No enableAnchoring(): core anchoring stays off.
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('hides the verify-anchor action when authorization denies it', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyAnchor')->table($entry));
});

it('exposes a verify-anchor header action on the detail view', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertActionVisible('verifyAnchor');
});
