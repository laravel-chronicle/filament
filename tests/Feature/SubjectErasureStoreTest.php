<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Entry\Entry;
use Chronicle\Filament\Support\ErasureState;
use Chronicle\Filament\Support\SubjectErasureStore;
use Chronicle\Lifecycle\LegalHold;
use Illuminate\Support\Facades\DB;

/**
 * Build an unsaved Entry carrying just a subject reference - enough for the
 * store's per-entry reads, which only touch subject_type/subject_id.
 */
function entryFor(?string $type, ?string $id): Entry
{
    return new Entry(['subject_type' => $type, 'subject_id' => $id]);
}

it('derives Encrypted, Erased, and NotEncrypted from SubjectKey status', function () {
    SubjectKey::create([
        'subject_type' => 'user', 'subject_id' => 'active-1',
        'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
        'created_at' => now(),
    ]);
    SubjectKey::create([
        'subject_type' => 'user', 'subject_id' => 'erased-1',
        'wrapped_dek' => null, 'kek_id' => 'local', 'status' => 'erased',
        'created_at' => now(), 'erased_at' => now(),
    ]);

    $store = SubjectErasureStore::forEntries([
        entryFor('user', 'active-1'),
        entryFor('user', 'erased-1'),
        entryFor('user', 'never-keyed'),
        entryFor(null, null),
    ]);

    expect($store->stateFor(entryFor('user', 'active-1')))->toBe(ErasureState::Encrypted)
        ->and($store->stateFor(entryFor('user', 'erased-1')))->toBe(ErasureState::Erased)
        ->and($store->stateFor(entryFor('user', 'never-keyed')))->toBe(ErasureState::NotEncrypted)
        ->and($store->stateFor(entryFor(null, null)))->toBe(ErasureState::NotEncrypted)
        ->and($store->kekIdFor(entryFor('user', 'active-1')))->toBe('local')
        ->and($store->erasedAtFor(entryFor('user', 'erased-1')))->not->toBeNull()
        ->and($store->erasedAtFor(entryFor('user', 'active-1')))->toBeNull();
});

it('derives legal hold from active LegalHold rows only', function () {
    LegalHold::place('user', 'held-1', 'litigation', 'officer');
    LegalHold::place('user', 'released-1');
    LegalHold::release('user', 'released-1');

    $store = SubjectErasureStore::forEntries([
        entryFor('user', 'held-1'),
        entryFor('user', 'released-1'),
    ]);

    expect($store->isHeld(entryFor('user', 'held-1')))->toBeTrue()
        ->and($store->isHeld(entryFor('user', 'released-1')))->toBeFalse()
        ->and($store->isHeld(entryFor('user', 'never-held')))->toBeFalse();
});

it('primes a whole page in a flat two queries and then reads query-free', function () {
    foreach (range(1, 10) as $n) {
        SubjectKey::create([
            'subject_type' => 'user', 'subject_id' => "u$n",
            'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
            'created_at' => now(),
        ]);
    }

    $entries = collect(range(1, 10))->map(fn (int $n) => entryFor('user', "u$n"))->all();

    DB::enableQueryLog();
    $store = SubjectErasureStore::forEntries($entries);

    expect(DB::getQueryLog())->toHaveCount(2); // SubjectKey + LegalHold, regardless of page size

    foreach ($entries as $entry) {
        $store->stateFor($entry);
        $store->isHeld($entry);
    }

    expect(DB::getQueryLog())->toHaveCount(2); // per-entry reads add nothing
    DB::disableQueryLog();
});

it('reads NotEncrypted and unheld when encryption is disabled (no rows)', function () {
    // No SubjectKey/LegalHold rows seeded - the encryption-off degrade path.
    $store = SubjectErasureStore::forEntries([entryFor('user', 'x')]);

    expect($store->stateFor(entryFor('user', 'x')))->toBe(ErasureState::NotEncrypted)
        ->and($store->isHeld(entryFor('user', 'x')))->toBeFalse();
});

it('issues no queries for an empty page', function () {
    DB::enableQueryLog();
    SubjectErasureStore::forEntries([]);
    expect(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();
});
