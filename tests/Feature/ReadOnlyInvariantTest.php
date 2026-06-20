<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

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
