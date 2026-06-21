<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Livewire\Livewire;

it('shows the entry fields from their correct sources with no edit affordances', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2); // checkpoint anchors entry 2

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();
    $previous = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        // current hash + linkage
        ->assertSee($entry->chain_hash)
        ->assertSee($previous->chain_hash)   // previous hash = prior entry's chain_hash
        ->assertSee($entry->payload_hash)
        // signature/algorithm/key id come from the checkpoint
        ->assertSee($entry->checkpoint->key_id)
        ->assertSee($entry->checkpoint->algorithm)
        // no mutating header actions
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('renders genesis previous hash for the first entry', function () {
    $this->seedLedger(count: 1, checkpointEvery: 1);

    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('0'); // genesis predecessor
});
