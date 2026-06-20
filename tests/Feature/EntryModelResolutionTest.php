<?php

declare(strict_types=1);

use Chronicle\Filament\Resources\ChronicleEntryResource;
use Chronicle\Filament\Tests\Fixtures\CustomEntry;

it('routes the resource through a configured Entry subclass', function () {
    config()->set('chronicle-filament.entry_model', CustomEntry::class);

    expect(ChronicleEntryResource::getModel())->toBe(CustomEntry::class)
        ->and(ChronicleEntryResource::getEloquentQuery()->getModel())->toBeInstanceOf(CustomEntry::class);
});
