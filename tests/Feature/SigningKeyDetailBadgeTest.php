<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Livewire\Livewire;

it('shows an Active badge for an entry signed by the active key', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2); // checkpoint under the active dev key

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Active');
});

it('shows a Retired badge and hint for an entry signed by an old key', function () {
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->retireCheckpoint($ledger->lastCheckpointId);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Retired')
        ->assertSee('still verifies historical entries');
});

it('shows no signing-key badge for an entry with no checkpoint', function () {
    $this->seedLedger(count: 1); // no checkpoint => checkpoint_id null

    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertDontSee('Key state');
});

it('hides the signing-key badge when the plugin forces signingKeys(false)', function () {
    ChronicleFilamentPlugin::get()->signingKeys(false);
    $this->seedLedger(count: 2, checkpointEvery: 2);

    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertDontSee('Key state');
});
