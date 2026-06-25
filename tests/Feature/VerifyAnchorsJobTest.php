<?php

declare(strict_types=1);

use Chronicle\Filament\Jobs\VerifyAnchorsJob;
use Chronicle\Filament\Tests\Fixtures\NotifiableUser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // The notify path resolves the user via the default auth provider and writes
    // a database notification; stand up the minimal tables and point the provider
    // at the fixture user model (mirrors JobNotificationTest).
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

it('verifies in-scope anchors and notifies the initiating user', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);
    $user = NotifiableUser::query()->create(['name' => 'Ada']);

    (new VerifyAnchorsJob($user->getKey()))->handle();

    expect($user->notifications()->count())->toBe(1);
});

it('notifies the initiating user when an in-scope anchor is invalid', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    // A tampered 'anchored' anchor fails AnchorVerifier::verify().
    $this->seedAnchor($ledger->lastCheckpointId, valid: false);
    $user = NotifiableUser::query()->create(['name' => 'Ada']);

    (new VerifyAnchorsJob($user->getKey()))->handle();

    expect($user->notifications()->count())->toBe(1)
        ->and(json_encode($user->notifications()->first()->data))
        ->toContain('Anchor verification failed');
});

it('does nothing when there is no user to notify', function () {
    $this->enableAnchoring();
    $ledger = $this->seedLedger(count: 2, checkpointEvery: 2);
    $this->seedAnchor($ledger->lastCheckpointId);

    // A null notify-user id resolves to no user; the job verifies but writes nothing.
    (new VerifyAnchorsJob(null))->handle();

    expect(DB::table('notifications')->count())->toBe(0);
});
