<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Verification\EntryVerifier;
use Livewire\Livewire;

it('narrows the table to entries with the selected verification state', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $verified = Entry::query()->where('sequence', 2)->firstOrFail();
    $others = Entry::query()->where('sequence', '!=', 2)->get();

    app(VerificationResultStore::class)
        ->recordEntry($verified->id, app(EntryVerifier::class)->verify($verified->id));

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('verification_status', 'verified')
        ->assertCanSeeTableRecords([$verified])
        ->assertCanNotSeeTableRecords($others);
});

it('narrows to unverified entries (no stored record)', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    $verified = Entry::query()->where('sequence', 1)->firstOrFail();
    $unverified = Entry::query()->where('sequence', '!=', 1)->get();

    app(VerificationResultStore::class)
        ->recordEntry($verified->id, app(EntryVerifier::class)->verify($verified->id));

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('verification_status', 'unverified')
        ->assertCanSeeTableRecords($unverified)
        ->assertCanNotSeeTableRecords([$verified]);
});
