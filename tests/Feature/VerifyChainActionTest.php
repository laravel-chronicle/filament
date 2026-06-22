<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

it('verifies the chain synchronously below the queue threshold', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 1000);
    $this->seedLedger(count: 6, checkpointEvery: 6);

    Livewire::test(ListEntries::class)
        ->callAction('verifyChain');

    expect(app(VerificationResultStore::class)->chainState())->toBe(VerificationState::Verified);
});

it('dispatches the chain verification to the queue above the threshold', function () {
    Config::set('chronicle-filament.verification.queue_threshold', 3);
    $this->seedLedger(count: 6, checkpointEvery: 6);
    Queue::fake();

    Livewire::test(ListEntries::class)
        ->callAction('verifyChain');

    Queue::assertPushed(VerifyLedgerJob::class, fn (VerifyLedgerJob $job): bool => $job->mode === 'chain');
});

it('records a verified chain when the queued job runs', function () {
    $this->seedLedger(count: 5, checkpointEvery: 5);

    (new VerifyLedgerJob('chain', null, null, null))->handle();

    expect(app(VerificationResultStore::class)->chainState())->toBe(VerificationState::Verified);
});

it('hides the verify-chain action when authorization denies it', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('verifyChain');
});
