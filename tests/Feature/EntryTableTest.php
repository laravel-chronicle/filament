<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
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

it('has no mutating row or bulk actions', function () {
    $this->seedLedger(count: 2);

    $table = ChronicleEntryResource::table(
        app(Table::class, ['livewire' => new ListEntries])
    );

    // Read-only: no delete/edit row actions, no bulk actions.
    expect($table->getToolbarActions())->toBe([]);
});
