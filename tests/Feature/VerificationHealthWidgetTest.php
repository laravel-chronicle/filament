<?php

declare(strict_types=1);

use Chronicle\Filament\Support\VerificationResultStore;
use Chronicle\Filament\Widgets\VerificationHealthWidget;
use Chronicle\Verification\IntegrityVerifier;
use Livewire\Livewire;

it('renders the chain state and spine check from cheap sources', function () {
    $this->seedLedger(checkpointEvery: 5);
    app(VerificationResultStore::class)->recordChain(app(IntegrityVerifier::class)->verify());

    Livewire::test(VerificationHealthWidget::class)
        ->assertOk()
        ->assertSee('Chain status')
        ->assertSee('Spine check');
});

it('does not run a full integrity re-hash on widget load', function () {
    $this->seedLedger(checkpointEvery: 5);

    // The widget may run the O(#checkpoints) CheckpointChainVerifier, but must
    // never trigger a full IntegrityVerifier re-hash on render. IntegrityVerifier
    // is final and cannot be Mockery-mocked, so bind a container spy that blows up
    // if the widget ever resolves and calls it during render.
    $this->app->bind(IntegrityVerifier::class, fn () => new class
    {
        public function verify(): never
        {
            throw new RuntimeException('IntegrityVerifier::verify() must not run during widget render');
        }
    });

    Livewire::test(VerificationHealthWidget::class)->assertOk();
});
