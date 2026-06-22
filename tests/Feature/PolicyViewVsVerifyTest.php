<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('lets a reader browse while every verify action is hidden when verify is denied', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    // Verification is gated independently of read access; deny it.
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertCanSeeTableRecords([$entry])                                  // reading still works
        ->assertActionHidden('verifyChain')                                   // header verify gated
        ->assertActionHidden(TestAction::make('verifyEntry')->table($entry))  // row verify gated
        ->assertTableBulkActionHidden('verifySegment');                       // bulk verify gated
});
