<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('verifies a selected segment span and records it', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 1000);
    $this->seedLedger(count: 8, checkpointEvery: 8);
    $selection = Entry::query()->whereBetween('sequence', [2, 5])->get();

    Livewire::test(ListEntries::class)
        ->selectTableRecords($selection)
        ->callAction(TestAction::make('verifySegment')->table()->bulk());

    expect(app(VerificationResultStore::class)->chainState('segment'))->toBe(VerificationState::Verified);
});

it('detects a tampered row inside the selected span', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 1000);
    $this->seedLedger(count: 8, checkpointEvery: 8);
    $selection = Entry::query()->whereBetween('sequence', [2, 6])->get();

    // Tamper with a row inside the span - mutate a hashed column directly in the
    // DB (bypassing the model's immutability guard) so the chain no longer
    // recomputes to the stored hash.
    $victim = Entry::query()->where('sequence', 4)->firstOrFail();
    DB::table($victim->getTable())->where('id', $victim->id)->update(['payload' => json_encode(['tampered' => true])]);

    Livewire::test(ListEntries::class)
        ->selectTableRecords($selection)
        ->callAction(TestAction::make('verifySegment')->table()->bulk());

    $record = app(VerificationResultStore::class)->chainRecord('segment');
    expect($record?->state)->toBe('failed')
        ->and($record?->failed_entry_id)->not->toBeNull();
});

it('dispatches a large segment to the queue', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 2);
    $this->seedLedger(count: 8, checkpointEvery: 8);
    $selection = Entry::query()->whereBetween('sequence', [1, 8])->get();
    Queue::fake();

    Livewire::test(ListEntries::class)
        ->selectTableRecords($selection)
        ->callAction(TestAction::make('verifySegment')->table()->bulk());

    Queue::assertPushed(VerifyLedgerJob::class, fn (VerifyLedgerJob $job): bool => $job->mode === 'segment' && $job->fromSequence === 1 && $job->toSequence === 8);
});

it('does nothing when the selection contains no entries', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 1000);
    $this->seedLedger(count: 3, checkpointEvery: 3);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->selectTableRecords(collect())
        ->callAction(TestAction::make('verifySegment')->table()->bulk());

    // No selection -> no span -> the action returns early without recording.
    expect(app(VerificationResultStore::class)->chainRecord('segment'))->toBeNull();
});

it('hides the verify-segment bulk action when authorization denies it', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('verifySegment')->table()->bulk());
});
