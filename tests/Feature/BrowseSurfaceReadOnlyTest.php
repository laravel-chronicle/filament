<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Livewire\Livewire;

it('renders list and view against seeded data with no mutating actions', function () {
    $this->seedLedger(count: 8, checkpointEvery: 4);

    $entry = Entry::query()->where('sequence', 5)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableActionDoesNotExist('delete')
        ->assertTableActionDoesNotExist('edit');

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertActionDoesNotExist('edit')
        ->assertActionDoesNotExist('delete');
});

it('requires no asset build: no custom theme css is shipped or referenced', function () {
    // Zero-build invariant: the package ships no compiled assets and registers
    // no custom Filament theme. Native CSS variables/classes only.
    expect(glob(__DIR__.'/../../resources/css/*'))->toBe([])
        ->and(glob(__DIR__.'/../../resources/dist/*'))->toBe([]);
});
