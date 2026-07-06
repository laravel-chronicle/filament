<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Jobs\ExportLedgerJob;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
});

it('queues the export and never runs it synchronously', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);

    Queue::fake();

    Livewire::test(ListEntries::class)
        ->callAction('exportLedger')
        ->assertNotified('Export queued');

    Queue::assertPushed(ExportLedgerJob::class);
});

it('makes the full-dataset egress explicit in the confirmation copy', function () {
    $this->seedLedger(count: 2);

    // Filament 5 renders action-modal copy client-side, so it is absent from the
    // server HTML that assertSee() inspects; read the description off the mounted
    // action instead.
    $description = Livewire::test(ListEntries::class)
        ->mountAction('exportLedger')
        ->assertActionMounted('exportLedger')
        ->instance()
        ->getMountedAction()
        ?->getModalDescription();

    expect((string) $description)->toContain('entire dataset');
});

it('hides the export action when exports are disabled', function () {
    $this->seedLedger(count: 2);
    ChronicleFilamentPlugin::get()->exports(false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('exportLedger');
});

it('hides the export action when the user cannot export', function () {
    $this->seedLedger(count: 2);
    // canExport() defaults to the verify gate; deny verify -> export hidden.
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('exportLedger');
});
