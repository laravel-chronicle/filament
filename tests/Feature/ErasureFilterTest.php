<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Lifecycle\LegalHold;
use Livewire\Livewire;

/**
 * Seed four entries (stdClass:1..4) and key three of them: 1 active (Encrypted),
 * 2 erased (Erased), 3 unkeyed (Not encrypted), 4 active + on hold.
 */
function seedFilterState(): void
{
    SubjectKey::create([
        'subject_type' => 'stdClass', 'subject_id' => '1',
        'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
        'created_at' => now(),
    ]);
    SubjectKey::create([
        'subject_type' => 'stdClass', 'subject_id' => '2',
        'wrapped_dek' => null, 'kek_id' => 'local', 'status' => 'erased',
        'created_at' => now(), 'erased_at' => now(),
    ]);
    SubjectKey::create([
        'subject_type' => 'stdClass', 'subject_id' => '4',
        'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
        'created_at' => now(),
    ]);
    LegalHold::place('stdClass', '4', 'litigation', 'officer');
}

it('filters the table by erasure state', function () {
    $this->seedLedger(count: 4);
    $this->enableEncryption();
    seedFilterState();

    // Only the erased subject (seq for stdClass:2) survives the Erased filter.
    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('erasure_state', 'erased')
        ->assertCanSeeTableRecords(Entry::query()->where('subject_id', '2')->get())
        ->assertCanNotSeeTableRecords(Entry::query()->where('subject_id', '1')->get());
});

it('filters the table by the Not encrypted state (no key row)', function () {
    $this->seedLedger(count: 4);
    $this->enableEncryption();
    seedFilterState();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('erasure_state', 'not_encrypted')
        ->assertCanSeeTableRecords(Entry::query()->where('subject_id', '3')->get())
        ->assertCanNotSeeTableRecords(Entry::query()->where('subject_id', '2')->get());
});

it('filters the table by legal hold', function () {
    $this->seedLedger(count: 4);
    $this->enableEncryption();
    seedFilterState();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('legal_hold', 'held')
        ->assertCanSeeTableRecords(Entry::query()->where('subject_id', '4')->get())
        ->assertCanNotSeeTableRecords(Entry::query()->where('subject_id', '1')->get());
});

it('presets the table to subject.erased proof entries', function () {
    $this->seedLedger(count: 2); // ordinary entries
    $this->enableEncryption();

    // Append a real subject.erased proof entry via core's builder (encryption is
    // off at commit time, so the requester/reason metadata is stored plain).
    Chronicle::record()
        ->actor((object) ['id' => 'officer'])
        ->action('subject.erased')
        ->subject((object) ['id' => '99'])
        ->metadata(['requester' => 'officer', 'reason' => 'GDPR erasure request'])
        ->commit();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->filterTable('erasure_proofs', true)
        ->assertCanSeeTableRecords(Entry::query()->where('action', 'subject.erased')->get())
        ->assertCanNotSeeTableRecords(Entry::query()->where('action', '!=', 'subject.erased')->get());
});
