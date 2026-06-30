<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Encryption\SubjectKeyManager;
use Chronicle\Entry\Entry;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ViewEntry;
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
