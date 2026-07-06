<?php

declare(strict_types=1);

use Chronicle\Exports\ExportManager;
use Chronicle\Filament\Support\ExportArtifact;
use Chronicle\Filament\Support\ExportArtifactStore;
use Chronicle\Verification\ExportVerifier;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    config()->set('chronicle-filament.exports.disk', 'local');
    config()->set('chronicle-filament.exports.path', 'chronicle-exports');
});

/** Produce a real, signed core export in a fresh local dir and return its path. */
function seedExportDir(): string
{
    $dir = sys_get_temp_dir().'/chronicle-export-test-'.Str::uuid();
    app(ExportManager::class)->export($dir);

    return $dir;
}

it('stores a signed bundle that re-verifies clean under core ExportVerifier', function () {
    $this->seedLedger(count: 3, checkpointEvery: 2);
    $dir = seedExportDir();

    $store = app(ExportArtifactStore::class);
    $artifact = $store->store($dir);

    // The bundle landed on the exports disk under the configured path.
    expect($artifact)->toBeInstanceOf(ExportArtifact::class)
        ->and($artifact->path)->toStartWith('chronicle-exports/')
        ->and($artifact->name)->toEndWith('.zip')
        ->and($artifact->sizeBytes)->toBeGreaterThan(0)
        ->and(Storage::disk('local')->exists($artifact->path))->toBeTrue();

    // Round-trip: extract the stored bundle and verify it under core.
    $extracted = $store->extractToLocalDir((string) Storage::disk('local')->get($artifact->path));
    $result = app(ExportVerifier::class)->verify($extracted);

    expect($result->isValid())->toBeTrue()
        ->and($result->entryCount())->toBe(3);

    $store->deleteLocalDir($extracted);
    $store->deleteLocalDir($dir);
    expect(is_dir($extracted))->toBeFalse();
});

it('rejects bytes that are not a readable zip archive', function () {
    $store = app(ExportArtifactStore::class);

    expect(fn () => $store->extractToLocalDir('these bytes are not a zip archive'))
        ->toThrow(RuntimeException::class, 'not a readable zip archive');
});

it('treats deleting an absent local dir as a no-op', function () {
    $store = app(ExportArtifactStore::class);
    $absent = sys_get_temp_dir().'/chronicle-absent-'.Str::uuid();

    $store->deleteLocalDir($absent);

    expect(is_dir($absent))->toBeFalse();
});

it('lists prior bundles newest-first and exposes the latest', function () {
    $this->seedLedger(count: 2);
    $store = app(ExportArtifactStore::class);

    $first = $store->store(seedExportDir());
    // Force a later mtime so ordering is deterministic on fast disks.
    touch(Storage::disk('local')->path($first->path), time() - 60);
    $second = $store->store(seedExportDir());

    $all = $store->all();

    expect($all)->toHaveCount(2)
        ->and($all->first()->name)->toBe($second->name)
        ->and($store->latest()?->name)->toBe($second->name);
});
