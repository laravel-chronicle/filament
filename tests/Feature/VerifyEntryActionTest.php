<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('verifies a single entry and records it as verified', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyEntry')->table($entry));

    expect(app(VerificationResultStore::class)->entryState($entry->id))
        ->toBe(VerificationState::Verified);
});

it('records a failed entry and surfaces the failure case', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();

    // Tamper the stored payload so the entry verifier reports a real failure.
    DB::table($entry->getTable())
        ->where('id', $entry->id)
        ->update(['payload' => json_encode(['tampered' => true])]);

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('verifyEntry')->table($entry));

    expect(app(VerificationResultStore::class)->entryState($entry->id))
        ->toBe(VerificationState::Failed);
});

it('hides the verify-entry action when authorization denies it', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifyEntry')->table($entry));
});

it('re-checks canVerify inside the action and refuses a crafted call (defense in depth)', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    // Authorization DENIES: visible() hides the button, so Filament never mounts it
    // in the UI. Reaching the ->action() closure directly is what a crafted request
    // does; the closure's own canVerify re-check must still refuse - recording no
    // verification result and telling the caller it is not permitted. This is a
    // separate assertion from "the button is hidden" (see the test above).
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    $closure = Livewire::test(ListEntries::class)->instance()->getTable()->getAction('verifyEntry')?->getActionFunction();
    expect($closure)->not->toBeNull();

    $closure($entry);

    expect(app(VerificationResultStore::class)->entryState($entry->id))
        ->toBe(VerificationState::Unverified);
    Notification::assertNotified('Verification is not permitted');
});
