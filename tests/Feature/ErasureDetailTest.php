<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Encryption\SubjectKeyManager;
use Chronicle\Entry\Entry;
use Chronicle\Facades\Chronicle;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
use Chronicle\Lifecycle\LegalHold;
use Livewire\Livewire;

it('shows the erasure state, KEK, and erased-at for an erased subject', function () {
    $this->seedLedger(count: 1); // entry with subject stdClass:1
    $this->enableEncryption();
    SubjectKey::create([
        'subject_type' => 'stdClass', 'subject_id' => '1',
        'wrapped_dek' => null, 'kek_id' => 'local', 'status' => 'erased',
        'created_at' => now(), 'erased_at' => now(),
    ]);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Subject erasure')
        ->assertSee('Erased')
        ->assertSee('local') // wrapping KEK id
        ->assertSee('permanently unreadable');
});

it('shows Encrypted with no erased-at for an active subject', function () {
    $this->seedLedger(count: 1);
    $this->enableEncryption();
    // Mint a real active key (valid wrapped_dek) via core, so the detail page's
    // existing erased()/decrypted accessors can unwrap it without throwing.
    app(SubjectKeyManager::class)->getOrCreate('stdClass', '1');

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('Encrypted')
        ->assertDontSee('permanently unreadable');
});

it('hides the erasure section when crypto-shredding is off', function () {
    ChronicleFilamentPlugin::get()->cryptoShredding(false);
    $this->seedLedger(count: 1);

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertDontSee('Subject erasure');
});

it('surfaces the requester and reason on a subject.erased proof entry', function () {
    $this->enableEncryption();

    Chronicle::record()
        ->actor((object) ['id' => 'officer'])
        ->action('subject.erased')
        ->subject((object) ['id' => '99'])
        ->metadata(['requester' => 'officer', 'reason' => 'GDPR erasure request'])
        ->commit();

    $entry = Entry::query()->where('action', 'subject.erased')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('officer')
        ->assertSee('GDPR erasure request');
});

it('surfaces the active hold reason and placed-at in detail', function () {
    $this->seedLedger(count: 1); // subject stdClass:1
    $this->enableEncryption();
    LegalHold::place('stdClass', '1', 'litigation hold', 'officer');

    $entry = Entry::query()->where('subject_id', '1')->firstOrFail();

    Livewire::test(ViewEntry::class, ['record' => $entry->getKey()])
        ->assertOk()
        ->assertSee('litigation hold');
});
