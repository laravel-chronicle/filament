<?php

declare(strict_types=1);

use Chronicle\Checkpoints\Checkpoint;
use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('shows the anchor badge column with the stored state when anchoring is on', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertSee('Anchored'); // AnchorState::Anchored label in the column
});

it('hides the anchor column and filter when anchoring is disabled', function () {
    // No enableAnchoring().
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableColumnHidden('anchor_state')
        ->assertTableFilterHidden('anchor_state');
});

it('hides the anchor column when the plugin forces anchoring(false)', function () {
    $this->enableAnchoring();
    ChronicleFilamentPlugin::get()->anchoring(false);
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableColumnHidden('anchor_state');
});

it('filters the table to anchored entries', function () {
    // Force the surface on at the plugin level with core anchoring off, so
    // seeding does not auto-anchor every checkpoint; then anchor only the first,
    // leaving the second genuinely unanchored - so the filter can isolate them.
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 4, checkpointEvery: 2); // checkpoints after seq 2 and 4
    $first = Entry::query()->where('sequence', 2)->firstOrFail();
    $second = Entry::query()->where('sequence', 4)->firstOrFail();

    // Anchor only the first checkpoint.
    $this->seedAnchor((string) $first->checkpoint_id);

    Livewire::test(ListEntries::class)
        ->loadTable() // the resource defers loading; trigger it before filtering
        ->filterTable('anchor_state', 'anchored')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);
});

it('filters the table to failed entries', function () {
    // Surface forced on, core off (no auto-anchor); anchor the first checkpoint
    // as failed and leave the second unanchored, so the filter can isolate them.
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $first = Entry::query()->where('sequence', 2)->firstOrFail();
    $second = Entry::query()->where('sequence', 4)->firstOrFail();

    $this->seedAnchor((string) $first->checkpoint_id, status: 'failed');

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('anchor_state', 'failed')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);
});

it('filters the table to pending entries', function () {
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $first = Entry::query()->where('sequence', 2)->firstOrFail();
    $second = Entry::query()->where('sequence', 4)->firstOrFail();

    $this->seedAnchor((string) $first->checkpoint_id, status: 'pending');

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('anchor_state', 'pending')
        ->assertCanSeeTableRecords([$first])
        ->assertCanNotSeeTableRecords([$second]);
});

it('filters the table to unanchored entries', function () {
    ChronicleFilamentPlugin::get()->anchoring();
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $first = Entry::query()->where('sequence', 2)->firstOrFail();
    $second = Entry::query()->where('sequence', 4)->firstOrFail();

    // Anchor only the first checkpoint; the second has no anchor rows, so its
    // entries are unanchored.
    $this->seedAnchor((string) $first->checkpoint_id);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('anchor_state', 'unanchored')
        ->assertCanSeeTableRecords([$second])
        ->assertCanNotSeeTableRecords([$first]);
});

it('renders the column without running a provider verify or a per-row query', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 6, checkpointEvery: 2);
    foreach (Checkpoint::query()->pluck('id') as $id) {
        $this->seedAnchor((string) $id);
    }

    DB::enableQueryLog();

    Livewire::test(ListEntries::class)->assertOk();

    $anchorQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], 'checkpoint_anchors'));

    // Anchors load via the eager-load, not per row: at most one anchors query.
    expect($anchorQueries->count())->toBeLessThanOrEqual(1);

    DB::disableQueryLog();
});
