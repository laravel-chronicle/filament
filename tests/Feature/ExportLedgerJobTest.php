<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Jobs\ExportLedgerJob;
use Chronicle\Filament\Support\ExportArtifactStore;
use Chronicle\Filament\Tests\Fixtures\NotifiableUser;
use Chronicle\Verification\ExportVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Config::set('chronicle-filament.exports.disk', 'local');
    Config::set('auth.providers.users.model', NotifiableUser::class);

    Schema::create('users', function ($table) {
        $table->id();
        $table->string('name')->nullable();
    });

    Schema::create('notifications', function ($table) {
        $table->uuid('id')->primary();
        $table->string('type');
        $table->morphs('notifiable');
        $table->text('data');
        $table->timestamp('read_at')->nullable();
        $table->timestamps();
    });
});

it('stores a verifiable bundle and notifies the initiating user', function () {
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $user = NotifiableUser::query()->create(['name' => 'Ada']);

    (new ExportLedgerJob($user->getKey()))->handle();

    $store = app(ExportArtifactStore::class);
    $latest = $store->latest();

    expect($latest)->not->toBeNull();

    $extracted = $store->extractToLocalDir((string) $store->disk()->get($latest->path));
    expect(app(ExportVerifier::class)->verify($extracted)->isValid())->toBeTrue();
    $store->deleteLocalDir($extracted);

    expect($user->notifications()->count())->toBe(1)
        ->and(json_encode($user->notifications()->first()->data))->toContain('Export ready');
});

it('writes no ledger entry - export is read-only', function () {
    $this->seedLedger(count: 4, checkpointEvery: 2);

    $countBefore = Entry::query()->count();
    $headBefore = Entry::query()->orderByDesc('sequence')->value('hash');

    (new ExportLedgerJob(null))->handle();

    expect(Entry::query()->count())->toBe($countBefore)
        ->and(Entry::query()->orderByDesc('sequence')->value('hash'))->toBe($headBefore)
        // Core verification still passes after the export.
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});
