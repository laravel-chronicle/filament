<?php

declare(strict_types=1);

use Chronicle\Filament\Support\ErasureState;

it('maps each state to a distinct badge color', function () {
    expect(ErasureState::Encrypted->color())->toBe('success')
        ->and(ErasureState::Erased->color())->toBe('danger')
        ->and(ErasureState::NotEncrypted->color())->toBe('gray');
});

it('exposes a human label and an icon per state', function () {
    expect(ErasureState::Encrypted->label())->toBe('Encrypted')
        ->and(ErasureState::Erased->label())->toBe('Erased')
        ->and(ErasureState::NotEncrypted->label())->toBe('Not encrypted')
        ->and(ErasureState::Encrypted->icon())->toBe('heroicon-o-lock-closed')
        ->and(ErasureState::Erased->icon())->toBe('heroicon-o-fire')
        ->and(ErasureState::NotEncrypted->icon())->toBe('heroicon-o-lock-open');
});
