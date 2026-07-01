<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKeyManager;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Lifecycle\LegalHold;
use Chronicle\Verification\IntegrityVerifier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/** Enable + authorize the erase action for this file's tests. */
function enableEraseGuard(): void
{
    ChronicleFilamentPlugin::get()->erasure()->eraseAuthorize(fn (): bool => true);
}

/**
 * Seed a checkpointed ledger and three subject states via core:
 * stdClass:1 encrypted (active key), stdClass:2 encrypted + on legal hold,
 * stdClass:3 already erased. Returns nothing - read the rows back by id.
 */
function seedThreeSubjectStates(): void
{
    // 3 subjects, a signed checkpoint every 3 entries so IntegrityVerifier
    // has real chain + signature work to do.
    test()->seedLedger(count: 3, checkpointEvery: 3);

    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1'); // encrypted
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '2'); // encrypted...
    LegalHold::place('stdClass', '2', 'litigation hold', 'officer'); // ...+ held
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '3'); // encrypted...
    Chronicle::eraseSubject('stdClass', '3', 'officer', 'prior erasure'); // ...+ erased
}

it('appends exactly one proof, mutates no entry, and still verifies after an erase', function () {
    $this->enableEncryption();
    enableEraseGuard();
    seedThreeSubjectStates(); // one subject.erased already exists (stdClass:3)

    // Sanity: the ledger verifies before the panel-driven erase.
    expect(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();

    // Snapshot every existing entry to prove immutability (G10) byte-for-byte.
    $before = Entry::query()->orderBy('sequence')
        ->get(['id', 'hash', 'sequence', 'action']);
    $proofsBefore = Entry::query()->where('action', 'subject.erased')->count();

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    // Drive the panel's only write: erase stdClass:1 through the action.
    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), [
            'confirm_subject' => $entry->subject_type.':'.$entry->subject_id,
            'reason' => 'GDPR Article 17 erasure request',
        ])
        ->assertNotified('Subject erased');

    // (a) Exactly one NEW subject.erased proof was appended (net +1).
    expect(Entry::query()->where('action', 'subject.erased')->count())
        ->toBe($proofsBefore + 1)
        // (b) Every pre-existing entry is byte-for-byte unchanged: no update, no delete.
        ->and(Entry::query()->count())->toBe($before->count() + 1); // only the append

    foreach ($before as $original) {
        $now = Entry::query()->find($original->id);
        expect($now)->not->toBeNull() // nothing was deleted
            ->and($now->hash)->toBe($original->hash) // hash untouched
            ->and($now->sequence)->toBe($original->sequence) // sequence untouched
            ->and($now->action)->toBe($original->action); // action untouched
    }

    // (c) The chain + signatures still verify end-to-end through core.
    $result = app(IntegrityVerifier::class)->verify();
    expect($result->isValid())->toBeTrue()
        ->and($result->checked())->toBe(Entry::query()->count());

    // (d) No mutating route was introduced by the write path.
    foreach (['create', 'edit', 'delete'] as $page) {
        expect(Route::has("filament.admin.resources.chronicle-entries.$page"))->toBeFalse();
    }
});

it('erases an unheld subject but still blocks a held one in the same ledger', function () {
    $this->enableEncryption();
    enableEraseGuard(); // hold override NOT allowed (default)
    seedThreeSubjectStates();

    $held = Entry::query()->where('subject_id', '2')->firstOrFail();

    // The held subject (stdClass:2) is refused - no new proof, ledger intact.
    $proofsBefore = Entry::query()->where('action', 'subject.erased')->count();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($held), [
            'confirm_subject' => $held->subject_type.':'.$held->subject_id,
            'reason' => 'attempt on a held subject',
        ])
        ->assertNotified('Subject is on legal hold');

    expect(Entry::query()->where('action', 'subject.erased')->count())
        ->toBe($proofsBefore) // nothing appended
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});

it('re-erasing an already-erased subject appends nothing and still verifies', function () {
    $this->enableEncryption();
    enableEraseGuard();
    seedThreeSubjectStates();

    $erased = Entry::query()->where('subject_id', '3')->firstOrFail(); // already erased
    $proofsBefore = Entry::query()->where('action', 'subject.erased')->count();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($erased), [
            'confirm_subject' => $erased->subject_type.':'.$erased->subject_id,
            'reason' => 'redundant erase',
        ])
        ->assertNotified('Subject already erased');

    expect(Entry::query()->where('action', 'subject.erased')->count())
        ->toBe($proofsBefore) // idempotent - no second proof
        ->and(app(IntegrityVerifier::class)->verify()->isValid())->toBeTrue();
});

it('leaves the resource read-only after an erase (no mutating route or affordance)', function () {
    $this->enableEncryption();
    enableEraseGuard();
    seedThreeSubjectStates();

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();
    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), [
            'confirm_subject' => $entry->subject_type.':'.$entry->subject_id,
            'reason' => 'GDPR erasure',
        ])
        ->assertNotified('Subject erased');

    // The write changed nothing about the resource's read-only shape.
    expect(ChronicleEntryResource::canCreate())->toBeFalse()
        ->and(ChronicleEntryResource::canEdit($entry))->toBeFalse()
        ->and(ChronicleEntryResource::canDelete($entry))->toBeFalse();
    foreach (['create', 'edit', 'delete'] as $page) {
        expect(Route::has("filament.admin.resources.chronicle-entries.$page"))->toBeFalse();
    }
});
