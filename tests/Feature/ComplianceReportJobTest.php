<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Jobs\ComplianceReportJob;
use Chronicle\Filament\Support\ComplianceReportStore;
use Chronicle\Filament\Tests\Fixtures\NotifiableUser;
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

it('stores a signed report and notifies the initiating user', function () {
    $this->seedLedger(count: 4, checkpointEvery: 2);
    $user = NotifiableUser::query()->create(['name' => 'Ada']);

    (new ComplianceReportJob(null, null, $user->getKey()))->handle();

    $store = app(ComplianceReportStore::class);
    $latest = $store->latest();

    expect($latest)->not->toBeNull()
        ->and($user->notifications()->count())->toBe(1)
        ->and(json_encode($user->notifications()->first()->data))->toContain('Report ready');
});

it('writes no ledger entry - report generation is read-only', function () {
    $this->seedLedger(count: 4, checkpointEvery: 2);

    $countBefore = Entry::query()->count();
    $headBefore = Entry::query()->orderByDesc('sequence')->value('hash');

    (new ComplianceReportJob(null, null, null))->handle();

    expect(Entry::query()->count())->toBe($countBefore)
        ->and(Entry::query()->orderByDesc('sequence')->value('hash'))->toBe($headBefore)
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});
