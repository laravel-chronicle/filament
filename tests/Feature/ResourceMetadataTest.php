<?php

declare(strict_types=1);

use Chronicle\Filament\Resources\ChronicleEntryResource;

it('exposes the documented navigation metadata from the plugin defaults', function () {
    expect(ChronicleEntryResource::getNavigationGroup())->toBe('Chronicle')
        ->and(ChronicleEntryResource::getNavigationSort())->toBeNull()
        ->and(ChronicleEntryResource::getNavigationIcon())->toBe('heroicon-o-shield-check')
        ->and(ChronicleEntryResource::getNavigationLabel())->toBe('Audit Log');
});
