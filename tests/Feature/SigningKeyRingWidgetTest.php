<?php

declare(strict_types=1);

use Chronicle\Filament\ChronicleFilamentPlugin;
use Chronicle\Filament\Resources\ChronicleEntryResource\Pages\ListEntries;
use Chronicle\Filament\Widgets\SigningKeyRingWidget;
use Livewire\Livewire;

it('summarises the signing key ring from cheap aggregates', function () {
    $this->registerRetiredKey(); // adds ed25519:retired-key (verify-only) to the ring
    $this->seedLedger(count: 6, checkpointEvery: 2); // 3 checkpoints, all under the active dev key

    Livewire::test(SigningKeyRingWidget::class)
        ->assertOk()
        ->assertSee('Active signing key')
        ->assertSee('ed25519:chronicle-dev-key')
        ->assertSee('Retired keys')
        ->assertSee('3 / 3'); // all 3 checkpoints signed by the active key
});

it('reports the number of keys in the ring including retired ones', function () {
    $this->registerRetiredKey(); // dev key (active) + retired-key (retired) = 2 keys
    $this->seedLedger(count: 2, checkpointEvery: 2);

    Livewire::test(SigningKeyRingWidget::class)
        ->assertOk()
        ->assertSee('2 keys in the ring');
});

it('is hidden when the signing-key surfaces are disabled', function () {
    ChronicleFilamentPlugin::get()->signingKeys(false);

    expect(SigningKeyRingWidget::canView())->toBeFalse();
});

it('mounts on the list-page header beside the other widgets', function () {
    $this->seedLedger(count: 2, checkpointEvery: 2);

    $page = Livewire::test(ListEntries::class)
        ->assertOk()
        ->instance();

    $widgets = (new ReflectionMethod($page, 'getHeaderWidgets'))->invoke($page);

    expect($widgets)->toContain(SigningKeyRingWidget::class);
});
