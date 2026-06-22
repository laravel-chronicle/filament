<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Jobs\VerifyLedgerJob;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Chronicle\Filament\Tests\Fixtures\NotifiableUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // The notify path resolves the user via the default auth provider and writes
    // a database notification; stand up the minimal tables and point the provider
    // at the fixture user model.
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

it('verifies a chain and notifies the initiating user on success', function () {
    $this->seedLedger(checkpointEvery: 5);
    $user = NotifiableUser::query()->create(['name' => 'Ada']);

    (new VerifyLedgerJob('chain', null, null, $user->getKey()))->handle();

    expect(app(VerificationResultStore::class)->chainState())->toBe(VerificationState::Verified)
        ->and($user->notifications()->count())->toBe(1);
});

it('verifies a segment and notifies the user of a failure', function () {
    $this->seedLedger(count: 6, checkpointEvery: 6);
    $user = NotifiableUser::query()->create(['name' => 'Babbage']);

    // Tamper a row inside the span so the segment verification fails.
    $victim = Entry::query()->where('sequence', 3)->firstOrFail();
    DB::table($victim->getTable())->where('id', $victim->id)->update(['payload' => json_encode(['tampered' => true])]);

    (new VerifyLedgerJob('segment', 1, 6, $user->getKey(), 'segment'))->handle();

    expect(app(VerificationResultStore::class)->chainState('segment'))->toBe(VerificationState::Failed)
        ->and($user->notifications()->count())->toBe(1);
});
