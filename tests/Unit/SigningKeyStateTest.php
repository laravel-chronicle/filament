<?php

declare(strict_types=1);

use Chronicle\Filament\Support\SigningKeyState;

it('maps each state to a distinct badge color', function () {
    expect(SigningKeyState::Active->color())->toBe('success')
        ->and(SigningKeyState::Retired->color())->toBe('warning')
        ->and(SigningKeyState::Unsigned->color())->toBe('gray');
});

it('exposes a human label and an icon per state', function () {
    expect(SigningKeyState::Active->label())->toBe('Active')
        ->and(SigningKeyState::Retired->label())->toBe('Retired')
        ->and(SigningKeyState::Unsigned->label())->toBe('Unsigned')
        ->and(SigningKeyState::Active->icon())->toBe('heroicon-o-key')
        ->and(SigningKeyState::Retired->icon())->toBe('heroicon-o-archive-box')
        ->and(SigningKeyState::Unsigned->icon())->toBe('heroicon-o-no-symbol');
});
