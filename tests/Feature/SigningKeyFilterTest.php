<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Livewire\Livewire;

it('filters the table to entries signed by the active key', function () {
    $this->registerRetiredKey();
    $this->seedLedger(count: 4, checkpointEvery: 2); // checkpoints after seq 2 and 4, both active
    $active = Entry::query()->where('sequence', 2)->firstOrFail();
    $retired = Entry::query()->where('sequence', 4)->firstOrFail();
    $this->retireCheckpoint((string) $retired->checkpoint_id, 'retired-key');

    Livewire::test(ListEntries::class)
        ->loadTable() // the resource defers loading; trigger it before filtering
        ->filterTable('signing_key', 'ed25519:chronicle-dev-key')
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$retired]);
});

it('filters the table to entries signed by a retired key', function () {
    $this->registerRetiredKey();
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $active = Entry::query()->where('sequence', 2)->firstOrFail();
    $retired = Entry::query()->where('sequence', 4)->firstOrFail();
    $this->retireCheckpoint((string) $retired->checkpoint_id, 'retired-key');

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('signing_key', 'ed25519:retired-key')
        ->assertCanSeeTableRecords([$retired])
        ->assertCanNotSeeTableRecords([$active]);
});

it('hides the signing-key filter when the plugin forces signingKeys(false)', function () {
    ChronicleFilamentPlugin::get()->signingKeys(false);
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableFilterHidden('signing_key');
});
