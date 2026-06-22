<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

it('lists entries with the documented columns, newest sequence first', function () {
    $this->seedLedger();

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords(Entry::query()->orderByDesc('sequence')->get())
        ->assertTableColumnExists('sequence')
        ->assertTableColumnExists('created_at')
        ->assertTableColumnExists('action')
        ->assertTableColumnExists('actor')
        ->assertTableColumnExists('subject');
});

it('does not run a query per row (no N+1) at volume', function () {
    $this->seedLedger(count: 40, checkpointEvery: 10);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    Livewire::test(ListEntries::class)->assertOk();

    // Page size is bounded; a per-row label/checkpoint query would scale with
    // 40 rows. Assert the render stays well under that.
    expect($queries)->toBeLessThan(15);
});

it('filters by action', function () {
    $this->seedLedger(count: 3);
    Chronicle::record()
        ->actor('system')
        ->action('invoice.paid')
        ->subject((object) ['id' => 99])
        ->commit();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('action', 'invoice.paid')
        ->assertCanSeeTableRecords(Entry::query()->where('action', 'invoice.paid')->get())
        ->assertCanNotSeeTableRecords(Entry::query()->where('action', '!=', 'invoice.paid')->get());
});

it('filters by a recorded date range', function () {
    $this->seedLedger(count: 3, checkpointEvery: 3);

    // Shift one entry far into the past so a bounded range excludes it.
    $old = Entry::query()->where('sequence', 1)->firstOrFail();
    DB::table($old->getTable())->where('id', $old->id)->update([
        'created_at' => '2000-01-01 00:00:00',
    ]);

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('recorded', ['from' => '2020-01-01', 'until' => '2999-01-01'])
        ->assertCanSeeTableRecords(Entry::query()->where('sequence', '!=', 1)->get())
        ->assertCanNotSeeTableRecords([$old]);
});

it('has no mutating row or bulk actions', function () {
    $this->seedLedger(count: 2);

    $table = ChronicleEntryResource::table(
        app(Table::class, ['livewire' => new ListEntries])
    );

    // Read-only ledger: the resource never permits create/edit/delete. The only
    // bulk action is the non-mutating segment verification added in CHF-10.
    $bulkActionNames = collect($table->getToolbarActions())
        ->flatMap(fn ($action) => $action instanceof BulkActionGroup ? $action->getActions() : [$action])
        ->map(fn ($action) => $action->getName())
        ->values()
        ->all();

    expect($bulkActionNames)->toBe(['verifySegment'])
        ->and(ChronicleEntryResource::canCreate())->toBeFalse()
        ->and(ChronicleEntryResource::canEdit(new Entry))->toBeFalse()
        ->and(ChronicleEntryResource::canDelete(new Entry))->toBeFalse();
});
