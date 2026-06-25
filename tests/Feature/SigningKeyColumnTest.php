<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('shows the signing-key column with the active key id', function () {
    // Seeder signs checkpoints with the active dev key => key_id chronicle-dev-key.
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('chronicle-dev-key');
});

it('shows the retired key id for a checkpoint signed under an old key', function () {
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->retireCheckpoint($ledger->lastCheckpointId);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('old-key');
});

it('shows the Unsigned placeholder for an entry with no checkpoint', function () {
    $this->seedLedger(count: 1); // no checkpoint => checkpoint_id null

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('Unsigned');
});

it('hides the signing-key column when the plugin forces signingKeys(false)', function () {
    ChronicleFilamentPlugin::get()->signingKeys(false);
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableColumnHidden('signing_key');
});

it('renders the signing-key column without a per-row checkpoint query', function () {
    $this->seedLedger(count: 6, checkpointEvery: 2); // 3 checkpoints under the active key

    DB::enableQueryLog();

    Livewire::test(ListEntries::class)->assertOk();

    $checkpointQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], 'chronicle_checkpoints'));

    // Eager-load (1) + the key-ring widget's single grouped aggregate (1).
    // Still constant - never proportional to the number of rows.
    expect($checkpointQueries->count())->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});
