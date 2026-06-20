<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

it('registers the plugin and the resource in the panel', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->hasPlugin('chronicle-filament'))->toBeTrue()
        ->and($panel->getPlugin('chronicle-filament'))->toBeInstanceOf(ChronicleFilamentPlugin::class)
        ->and($panel->getResources())->toContain(ChronicleEntryResource::class);
});

it('resolves the entry model from config', function () {
    expect(ChronicleEntryResource::getModel())->toBe(Entry::class)
        ->and(ChronicleEntryResource::getEloquentQuery()->getModel())->toBeInstanceOf(Entry::class);
});

it('exposes only index and view pages', function () {
    expect(array_keys(ChronicleEntryResource::getPages()))->toBe(['index', 'view']);
});

it('registers no mutating routes', function () {
    expect(Route::has('filament.admin.resources.chronicle-entries.index'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.chronicle-entries.view'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.chronicle-entries.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.chronicle-entries.edit'))->toBeFalse();
});

it('denies every mutation ability at the resource layer', function () {
    $entry = new Entry;

    expect(ChronicleEntryResource::canViewAny())->toBeTrue()
        ->and(ChronicleEntryResource::canView($entry))->toBeTrue()
        ->and(ChronicleEntryResource::canCreate())->toBeFalse()
        ->and(ChronicleEntryResource::canEdit($entry))->toBeFalse()
        ->and(ChronicleEntryResource::canDelete($entry))->toBeFalse()
        ->and(ChronicleEntryResource::canDeleteAny())->toBeFalse()
        ->and(ChronicleEntryResource::canReplicate($entry))->toBeFalse();
});
