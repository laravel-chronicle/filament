<?php

declare(strict_types=1);

use Chronicle\Encryption\SubjectKey;
use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Widgets\CryptoShreddingWidget;
use Chronicle\Lifecycle\LegalHold;
use Livewire\Livewire;

function seedWidgetState(): void
{
    foreach (['1', '2'] as $id) {
        SubjectKey::create([
            'subject_type' => 'stdClass', 'subject_id' => $id,
            'wrapped_dek' => 'wrapped', 'kek_id' => 'local', 'status' => 'active',
            'created_at' => now(),
        ]);
    }
    SubjectKey::create([
        'subject_type' => 'stdClass', 'subject_id' => '3',
        'wrapped_dek' => null, 'kek_id' => 'local', 'status' => 'erased',
        'created_at' => now(), 'erased_at' => now(),
    ]);
    LegalHold::place('stdClass', '1', 'litigation', 'officer');
}

it('summarises encrypted, erased, on-hold counts and the active KEK', function () {
    $this->enableEncryption();
    seedWidgetState();

    Livewire::test(CryptoShreddingWidget::class)
        ->assertOk()
        ->assertSee('Encrypted subjects')
        ->assertSee('2')
        ->assertSee('Erased subjects')
        ->assertSee('On legal hold')
        ->assertSee('Active KEK')
        ->assertSee('local');
});

it('is hidden when crypto-shredding is disabled', function () {
    ChronicleFilamentPlugin::get()->cryptoShredding(false);

    expect(CryptoShreddingWidget::canView())->toBeFalse();
});

it('mounts on the list-page header beside the other widgets', function () {
    $this->seedLedger(count: 2);

    $page = Livewire::test(ListEntries::class)
        ->assertOk()
        ->instance();

    $widgets = (new ReflectionMethod($page, 'getHeaderWidgets'))->invoke($page);

    expect($widgets)->toContain(CryptoShreddingWidget::class);
});
