<?php

declare(strict_types=1);

use Chronicle\Entry\Entry;
use Chronicle\Filament\Support\VerificationRecord;
use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Support\VerificationState;
use Chronicle\Verification\EntryVerifier;
use Chronicle\Verification\IntegrityVerifier;
use Illuminate\Support\Facades\DB;

it('persists a verification record row on the store table', function () {
    $record = VerificationRecord::query()->create([
        'scope' => 'chain',
        'subject_key' => 'default',
        'state' => 'verified',
        'failure_code' => null,
        'failed_entry_id' => null,
        'checked_count' => 5,
        'verified_through' => 5,
        'last_verified_at' => now(),
    ]);

    expect($record->exists)->toBeTrue()
        ->and(VerificationRecord::query()->where('scope', 'chain')->count())->toBe(1)
        ->and($record->fresh()->checked_count)->toBe(5);
});

it('round-trips a chain verification and reads it back as verified', function () {
    $this->seedLedger(checkpointEvery: 5);
    $result = app(IntegrityVerifier::class)->verify();
    $store = app(VerificationResultStore::class);

    $store->recordChain($result);

    expect($store->chainState())->toBe(VerificationState::Verified)
        ->and($store->chainRecord()?->checked_count)->toBe(5)
        ->and($store->chainRecord()?->last_verified_at)->not->toBeNull();
});

it('reports a chain as stale once newer entries are appended', function () {
    $this->seedLedger(checkpointEvery: 5);
    $store = app(VerificationResultStore::class);
    $store->recordChain(app(IntegrityVerifier::class)->verify());

    expect($store->chainState())->toBe(VerificationState::Verified);

    $this->seedLedger(count: 3); // head moves past verified_through

    expect($store->chainState())->toBe(VerificationState::Stale);
});

it('round-trips a single-entry verification', function () {
    $this->seedLedger(count: 4, checkpointEvery: 4);
    $entry = Entry::query()->where('sequence', 2)->firstOrFail();
    $store = app(VerificationResultStore::class);

    $store->recordEntry($entry->id, app(EntryVerifier::class)->verify($entry->id));

    expect($store->entryState($entry->id))->toBe(VerificationState::Verified);
});

it('returns unverified for an entry with no record and primes in one query', function () {
    $this->seedLedger(count: 6, checkpointEvery: 6);
    $ids = Entry::query()->pluck('id')->all();
    $store = app(VerificationResultStore::class);

    $store->primeEntries($ids);

    DB::enableQueryLog();
    foreach ($ids as $id) {
        expect($store->entryState($id))->toBe(VerificationState::Unverified);
    }
    expect(DB::getQueryLog())->toHaveCount(0); // primed: zero further queries
});
