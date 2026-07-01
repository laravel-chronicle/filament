<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKeyManager;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Lifecycle\LegalHold;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Enable the erase action: turn the off-by-default flag on and set an
 * eraseAuthorize closure (deny-by-default otherwise). The plugin is a
 * per-test-fresh container singleton, so this state does not leak between tests.
 */
function enableErase(bool $authorize = true): void
{
    ChronicleFilamentPlugin::get()
        ->erasure()
        ->eraseAuthorize(fn (): bool => $authorize);
}

/**
 * The exact type-to-confirm token plus a reason, as the modal form expects.
 *
 * @return array{confirm_subject: string, reason: string}
 */
function eraseData(Entry $entry): array
{
    return [
        'confirm_subject' => $entry->subject_type.':'.$entry->subject_id,
        'reason' => 'GDPR Article 17 erasure request',
    ];
}

it('hides the erase action when erasure is disabled (off by default)', function () {
    $this->enableEncryption();
    // Authorize is set, but the erasure flag stays OFF -> still hidden.
    ChronicleFilamentPlugin::get()->eraseAuthorize(fn (): bool => true);
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('eraseSubject')->table($entry));
});

it('hides the erase action when no authorize closure is set (deny by default)', function () {
    $this->enableEncryption();
    // Flag ON, but no eraseAuthorize closure -> canErase() denies -> hidden.
    ChronicleFilamentPlugin::get()->erasure();
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('eraseSubject')->table($entry));
});

it('hides the erase action when the authorize closure denies', function () {
    $this->enableEncryption();
    enableErase(authorize: false);
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionHidden(TestAction::make('eraseSubject')->table($entry));
});

it('shows the erase action only when enabled and authorized', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->assertActionVisible(TestAction::make('eraseSubject')->table($entry));
});

it('hides the erase action for an entry with no subject', function () {
    $this->enableEncryption();
    enableErase();

    // Core 1.13 forbids committing a subject-less entry (the subject is mandatory
    // and the columns are NOT NULL), so such a row can never exist in the ledger.
    // A null subject only occurs on an unpersisted model, so assert the single
    // visibility gate directly: even with erasure enabled and authorized, an entry
    // without a subject (G4) is never erasable -> the action is hidden.
    $entry = new Entry;

    $gate = new ReflectionMethod(ChronicleEntryResource::class, 'canEraseSubject');

    expect($gate->invoke(null, $entry))->toBeFalse();
});

it('erases a confirmed subject: appends exactly one proof and mutates no entry', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 3); // subjects stdClass:1,2,3
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1'); // real active key

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    // Snapshot the existing ledger to prove immutability (G10).
    $before = Entry::query()->orderBy('sequence')->get(['id', 'hash', 'sequence', 'action']);

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), eraseData($entry))
        ->assertNotified('Subject erased');

    // Exactly one subject.erased proof was appended.
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(1);

    // Every pre-existing entry is byte-for-byte unchanged: same id, hash, action.
    foreach ($before as $original) {
        $now = Entry::query()->find($original->id);
        expect($now)->not->toBeNull()
            ->and($now->hash)->toBe($original->hash)
            ->and($now->action)->toBe($original->action);
    }

    // The proof carries the reason and no legal_hold_override (none was overridden).
    $proof = Entry::query()->where('action', 'subject.erased')->firstOrFail();
    expect($proof->metadata['reason'] ?? null)->toBe('GDPR Article 17 erasure request')
        ->and($proof->metadata['legal_hold_override'] ?? null)->toBeNull();
});

it('rejects the erase when the type-to-confirm token is wrong', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 1);
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1');

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), [
            'confirm_subject' => 'stdClass:WRONG',
            'reason' => 'GDPR request',
        ])
        ->assertHasFormErrors(['confirm_subject']);

    // No proof appended - the form never submitted.
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(0);
});

it('rejects the erase when no reason is given', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 1);
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1');

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), [
            'confirm_subject' => $entry->subject_type.':'.$entry->subject_id,
            'reason' => '',
        ])
        ->assertHasFormErrors(['reason']);

    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(0);
});

it('blocks the erase while the subject is on legal hold', function () {
    $this->enableEncryption();
    enableErase(); // override NOT allowed (default)
    $this->seedLedger(count: 1);
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1');
    LegalHold::place('stdClass', '1', 'litigation hold', 'officer');

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), eraseData($entry))
        ->assertNotified('Subject is on legal hold');

    // Held -> no erase: no proof appended.
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(0);
});

it('treats a re-erase as a friendly no-op', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 1);
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1');

    // First erase via core directly so the subject is already shredded.
    Chronicle::eraseSubject('stdClass', '1', 'officer', 'first erase');
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ListEntries::class)
        ->callAction(TestAction::make('eraseSubject')->table($entry), eraseData($entry))
        ->assertNotified('Subject already erased');

    // Still exactly one proof - re-erase appended nothing.
    expect(Entry::query()->where('action', 'subject.erased')->count())->toBe(1);
});

it('exposes the erase action on the detail-view header', function () {
    $this->enableEncryption();
    enableErase();
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertActionVisible('eraseSubject');
});
