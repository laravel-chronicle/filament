<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Lifecycle\LegalHold;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Seed three entries (default subjects stdClass:1,2,3), then stamp crypto-shredding
 * state directly onto those subject references: an active key (Encrypted), an erased
 * tombstone (Erased), and a legal hold. Subject 3 has no key (Not encrypted). The
 * SubjectKey rows are created with encryption OFF so nothing is auto-minted; the
 * surface then reads them with the visibility gate forced on.
 */
function seedErasureState(): void
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
    LegalHold::place('stdClass', '1', 'litigation', 'officer');
}

it('shows the erasure badge column with each subject state', function () {
    $this->seedLedger(count: 3);
    $this->enableEncryption(); // turns the visibility gate on
    seedErasureState();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('Encrypted')
        ->assertSee('Erased')
        ->assertSee('Not encrypted');
});

it('shows an On hold indicator for a held subject', function () {
    $this->seedLedger(count: 3);
    $this->enableEncryption();
    seedErasureState();

    Livewire::test(ListEntries::class)
        ->loadTable()
        ->assertOk()
        ->assertSee('On hold');
});

it('hides the erasure column when crypto-shredding is off', function () {
    ChronicleFilamentPlugin::get()->cryptoShredding(false);
    $this->seedLedger(count: 2);

    Livewire::test(ListEntries::class)
        ->assertOk()
        ->assertTableColumnHidden('erasure_state');
});

it('renders the erasure column in a flat two subject-state queries', function () {
    $this->seedLedger(count: 8);
    $this->enableEncryption();
    foreach (range(1, 8) as $n) {
        SubjectKey::create([
            'subject_type' => 'stdClass', 'subject_id' => (string) $n,
            'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
            'created_at' => now(),
        ]);
    }

    DB::enableQueryLog();

    Livewire::test(ListEntries::class)->loadTable()->assertOk();

    $stateQueries = collect(DB::getQueryLog())
        ->filter(fn (array $q): bool => str_contains((string) $q['query'], 'chronicle_subject_keys')
            || str_contains((string) $q['query'], 'chronicle_legal_holds'));

    // One SubjectKey priming query + one LegalHold priming query. Constant - never
    // proportional to the number of rows, and never a per-row DEK unwrap.
    expect($stateQueries->count())->toBeLessThanOrEqual(2);

    DB::disableQueryLog();
});
