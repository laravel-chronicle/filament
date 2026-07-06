<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

it('exposes no mutating routes for the resource', function () {
    $mutating = ['create', 'edit', 'delete'];

    foreach ($mutating as $page) {
        expect(Route::has("filament.admin.resources.chronicle-entries.$page"))
            ->toBeFalse("a mutating route '$page' was added - the read-only invariant is broken");
    }
});

it('denies mutation at both the resource and the Gate layer', function () {
    $entry = new Entry;

    expect(ChronicleEntryResource::canCreate())->toBeFalse()
        ->and(ChronicleEntryResource::canEdit($entry))->toBeFalse()
        ->and(ChronicleEntryResource::canDelete($entry))->toBeFalse()
        ->and(Gate::denies('update', $entry))->toBeTrue()
        ->and(Gate::denies('delete', $entry))->toBeTrue();
});

it('exposes only the read-only Verify-anchor header action on the view page', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);
    $entry = Entry::query()->where('sequence', 1)->firstOrFail();

    $page = Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->instance();

    // getHeaderActions() is protected; reach it via reflection. The detail page
    // exposes only deliberate, non-entry-mutating actions: the Verify-anchor
    // action (reads/records a verification result) and the off-by-default
    // Erase-subject action (the panel's only write - it destroys a subject DEK
    // and APPENDS a proof via core, never updating or deleting an entry, and is
    // hidden unless separately enabled and authorized). No entry-mutating action
    // (edit/delete) may ever be added.
    $headerActions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
    $names = array_map(fn (object $action): string => $action->getName(), $headerActions);

    expect($names)
        ->toBe(['verifyAnchor', 'eraseSubject'], 'an unexpected header action was added to ViewEntry - the read-only invariant is broken')
        ->not->toContain('edit')
        ->not->toContain('delete');
});

it('exposes only read-only header actions on the list page', function () {
    $this->enableAnchoring();
    $this->seedLedger(count: 2, checkpointEvery: 2);

    $page = Livewire::test(ListEntries::class)
        ->assertOk()
        ->instance();

    // The list page is strictly read-only w.r.t. the ledger: the only header
    // actions permitted are the deliberate verify actions (they read/record/notify)
    // and the queued export action (it reads the full dataset and writes only
    // artifact files to the exports disk, appending nothing to the ledger). No
    // entry- or anchor-mutating action may ever be added.
    $headerActions = (new ReflectionMethod($page, 'getHeaderActions'))->invoke($page);
    $names = array_map(fn (object $action): string => $action->getName(), $headerActions);

    expect($names)
        ->toBe(['exportLedger', 'verifyChain', 'verifyAllAnchors'], 'an unexpected header action was added to ListEntries - the read-only invariant is broken')
        ->not->toContain('edit')
        ->not->toContain('delete');
});
