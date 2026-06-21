<?php

declare(strict_types=1);

use Chronicle\Filament\Support\VerificationState;

it('maps each state to a distinct badge color', function () {
    expect(VerificationState::Verified->color())->toBe('success')
        ->and(VerificationState::Failed->color())->toBe('danger')
        ->and(VerificationState::Unverified->color())->toBe('gray')
        ->and(VerificationState::Stale->color())->toBe('warning');
});

it('exposes a human label and an icon per state', function () {
    expect(VerificationState::Verified->label())->toBe('Verified')
        ->and(VerificationState::Stale->label())->toBe('Stale')
        ->and(VerificationState::Failed->icon())->toBe('heroicon-o-x-circle')
        ->and(VerificationState::Verified->icon())->toBe('heroicon-o-check-badge');
});
