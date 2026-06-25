<?php

declare(strict_types=1);

use Chronicle\Filament\Jobs\VerifyAnchorsJob;
use Chronicle\Filament\Tests\Fixtures\NotifiableUser;
use Illuminate\Support\Facades\Config;
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
