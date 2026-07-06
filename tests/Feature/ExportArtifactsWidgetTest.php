<?php

declare(strict_types=1);

use Chronicle\Exports\ExportManager;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\ExportArtifactStore;
use Chronicle\Filament\Widgets\ExportArtifactsWidget;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
});

function storeOneBundle(): string
{
    $dir = sys_get_temp_dir().'/chronicle-export-w-'.Str::uuid();
    app(ExportManager::class)->export($dir);
    $artifact = app(ExportArtifactStore::class)->store($dir);
    app(ExportArtifactStore::class)->deleteLocalDir($dir);

    return $artifact->name;
}

it('hides the widget when exports are disabled', function () {
    ChronicleFilamentPlugin::get()->exports(false);

    expect(ExportArtifactsWidget::canView())->toBeFalse();
});

it('shows the latest bundle name in the widget', function () {
    $this->seedLedger(count: 2);
    $name = storeOneBundle();

    Livewire::test(ExportArtifactsWidget::class)
        ->assertSee($name);
});

it('streams the latest bundle from the download action', function () {
    $this->seedLedger(count: 2);
    $name = storeOneBundle();

    Livewire::test(ListEntries::class)
        ->callAction('downloadLatestExport')
        ->assertFileDownloaded($name);
});

it('hides the download action when the user cannot export', function () {
    $this->seedLedger(count: 2);
    storeOneBundle();
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('downloadLatestExport');
});
