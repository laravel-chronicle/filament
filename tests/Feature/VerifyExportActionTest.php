<?php

declare(strict_types=1);

use Chronicle\Exports\ExportManager;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Support\ExportArtifact;
use Chronicle\Filament\Support\ExportArtifactStore;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
});

/** Store a real signed bundle; optionally corrupt signature.json before zipping. */
function storeBundle(bool $tamper = false): ExportArtifact
{
    $dir = sys_get_temp_dir().'/chronicle-export-vx-'.Str::uuid();
    app(ExportManager::class)->export($dir);

    if ($tamper) {
        file_put_contents($dir.'/signature.json', '{"signature":"tampered"}');
    }

    $artifact = app(ExportArtifactStore::class)->store($dir);
    app(ExportArtifactStore::class)->deleteLocalDir($dir);

    return $artifact;
}

it('reports a valid bundle as verified', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);
    $bundle = storeBundle();

    Livewire::test(ListEntries::class)
        ->callAction('verifyExport', data: ['bundle' => $bundle->path])
        ->assertNotified('Export verified');
});

it('reports a tampered bundle as invalid with a reason', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);
    $bundle = storeBundle(tamper: true);

    Livewire::test(ListEntries::class)
        ->callAction('verifyExport', data: ['bundle' => $bundle->path])
        ->assertNotified('Export verification failed');
});

it('hides the verify-export action when exports are disabled', function () {
    $this->seedLedger(count: 2);
    ChronicleFilamentPlugin::get()->exports(false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('verifyExport');
});

it('hides the verify-export action when the user cannot export', function () {
    $this->seedLedger(count: 2);
    ChronicleFilamentPlugin::get()->authorize(fn (): bool => false);

    Livewire::test(ListEntries::class)
        ->assertActionHidden('verifyExport');
});
