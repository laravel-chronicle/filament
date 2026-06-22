<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

it('verifies a single entry and records it as verified', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyEntry')->table($entry));

    expect(app(VerificationResultStore::class)->entryState($entry->id))->toBe(VerificationState::Verified);
});

it('hides the verify-entry action when authorization denies it', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyEntry')->table($entry));
});
