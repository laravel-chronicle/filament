<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Livewire\Livewire;

it('renders the anchor section for an anchored checkpoint', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('External anchoring')
        ->assertSee('Anchored')   // AnchorState badge label
        ->assertSee('ref-anchored'); // the anchor reference
});

it('renders every provider for a multi-provider checkpoint', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);
    $this->seedAnchor($ledger->lastCheckpointId, status: 'pending', provider: 'rfc3161');

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('null')
        ->assertSee('rfc3161');
});

it('shows Unanchored when the entry has no checkpoint', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 1); // no checkpoint => checkpoint_id null

    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('External anchoring')
        ->assertSee('Unanchored');
});

it('shows No anchors when the checkpoint has no anchor rows', function () {
    // Force the surface on at the plugin level while core anchoring stays off,
    // so seeding does not auto-anchor the checkpoint, and it has zero anchor rows.
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2); // checkpoint exists, no anchors seeded

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('No anchors');
});

it('shows Anchoring not configured when anchoring is disabled', function () {
    // Do NOT call enableAnchoring(): core anchoring stays off (default).
    $this->seedLedger(count: 2, checkpointEvery: 2);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Anchoring not configured');
});
