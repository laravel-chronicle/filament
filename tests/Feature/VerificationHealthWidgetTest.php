<?php

declare(strict_types=1);

use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Widgets\VerificationHealthWidget;
use Chronicle\Verification\IntegrityVerifier;
use Livewire\Livewire;

it('renders the chain state and spine check from cheap sources', function () {
    $this->seedLedger(count: 5, checkpointEvery: 5);
    app(VerificationResultStore::class)->recordChain(app(IntegrityVerifier::class)->verify());

    Livewire::test(VerificationHealthWidget::class)
        ->assertOk()
        ->assertSee('Chain status')
        ->assertSee('Spine check');
});

it('does not run a full integrity re-hash on widget load', function () {
    $this->seedLedger(count: 5, checkpointEvery: 5);

    // The widget may run the O(#checkpoints) CheckpointChainVerifier, but must
    // never trigger a full IntegrityVerifier re-hash on render.
    $this->mock(IntegrityVerifier::class)->shouldNotReceive('verify')->shouldNotReceive('verifyEntryRange');

    Livewire::test(VerificationHealthWidget::class)->assertOk();
});
